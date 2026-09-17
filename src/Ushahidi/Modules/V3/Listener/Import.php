<?php
namespace Ushahidi\Modules\V3\Listener;

/**
 * Ushahidi PostSet Listener
 *
 * Listens for new posts that are added to a set
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi\Application
 * @copyright  2014 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

use Illuminate\Support\Facades\DB;
use League\Event\AbstractListener;
use League\Event\EventInterface;
use Ushahidi\Core\Entity\Set;
use Ushahidi\Core\Facade\Feature;

class Import extends AbstractListener
{
    /**
     * Columns a Kobo export uses to identify a submission, best first.
     *
     * `_uuid` is stable across repeated exports of the same submission, which
     * is what makes it usable as a deduplication key. `meta/rootUuid` tracks
     * the original submission through edits, and `_id` is the numeric server
     * id, kept last as a fallback for exports that omit the others.
     */
    const DEDUPE_COLUMNS = ['_uuid', 'meta/rootUuid', '_id'];

    protected $transformer;
    protected $repo;

    /**
     * [transform description]
     * @return [type] [description]
     */
    protected function transform($record)
    {
        $record = $this->transformer->interact($record);

        return $this->repo->getEntity()->setState($record);
    }

    /**
     * Pick the column that identifies a source row.
     *
     * Detected from the file rather than chosen in the mapping screen: these
     * are Kobo metadata columns, not survey questions, so they never appear in
     * the list of fields an operator can map.
     */
    protected function findDedupeColumn(array $record)
    {
        foreach (self::DEDUPE_COLUMNS as $column) {
            if (array_key_exists($column, $record)) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Has this source row already been imported into this survey?
     *
     * An indexed lookup per row, which is negligible beside the ~26 value rows
     * each post writes. The unique index on the table is the real guarantee;
     * this only avoids creating a post we would then have to discard.
     */
    protected function alreadyImported($form_id, $source_uuid)
    {
        return DB::table('post_import_keys')
            ->where('form_id', $form_id)
            ->where('source_uuid', $source_uuid)
            ->exists();
    }

    /**
     * Remember which source row a post came from.
     *
     * Returns false if another row claimed the same key first, which can only
     * happen if the same uuid appears twice in one file or two imports overlap.
     * The caller treats that as a skip rather than an error.
     */
    protected function rememberImported($post_id, $form_id, $source_uuid)
    {
        try {
            DB::table('post_import_keys')->insert([
                'post_id' => $post_id,
                'form_id' => $form_id,
                'source_uuid' => $source_uuid,
                'created' => time(),
            ]);

            return true;
        } catch (\Illuminate\Database\QueryException $e) {
            // 23000 covers the unique constraint; anything else is a real fault.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return false;
        }
    }

    /**
     * Remove a post that turned out to duplicate one already imported.
     *
     * Done against the database rather than through the repository because the
     * ImportRepository contract only promises create and update. Child rows
     * cascade on posts.id; translations are a polymorphic relation with no
     * foreign key, so they are cleared explicitly.
     */
    protected function discardPost($post_id)
    {
        DB::table('translations')
            ->where('translatable_type', 'post')
            ->where('translatable_id', $post_id)
            ->delete();

        DB::table('posts')->where('id', $post_id)->delete();
    }

    public function handle(
        EventInterface $event,
        $records = null,
        $csv = null,
        $transformer = null,
        $repo = null,
        $importUsecase = null
    ) {
        $this->transformer = $transformer;
        $this->repo = $repo;
        $processed = $errors = $skipped = 0;

        // Deduplication is per survey, so posts imported into one survey do not
        // suppress the same source rows being imported into another.
        $form_id = is_array($csv->fixed) ? ($csv->fixed['form'] ?? null) : null;
        $dedupe_column = null;
        $dedupe_checked = false;

        // Created on first use rather than up front: re-importing a file is
        // now an ordinary thing to do, and a run that skips every row would
        // otherwise leave an empty collection behind in the Data view.
        $collection_id = null;

        $created_entities = [];
        foreach ($records as $index => $record) {
            // Established from the first row; every row shares the same header.
            if (!$dedupe_checked) {
                $dedupe_column = $this->findDedupeColumn($record);
                $dedupe_checked = true;
            }

            $source_uuid = null;
            if ($form_id && $dedupe_column) {
                $source_uuid = trim((string) ($record[$dedupe_column] ?? ''));

                if ($source_uuid !== '' && $this->alreadyImported($form_id, $source_uuid)) {
                    $skipped++;
                    continue;
                }
            }

            try {
                // ... transform record
                $entity = $this->transform($record);

                // Ensure that empty status or under review is correctly mapped to draft
                if (is_null($entity->status) || strcasecmp($entity->status, 'under review') == 0) {
                    $entity->setState(['status' => 'draft']);
                }

                if (!Feature::isEnabled('csv-speedup')) {
                    $importUsecase->verify($entity);
                }
                // ... persist the new entity
                $id = $this->repo->create($entity);
            } catch (\Exception $e) {
                $errors++;
                continue;
            }

            if ($source_uuid !== null && $source_uuid !== '') {
                if (!$this->rememberImported($id, $form_id, $source_uuid)) {
                    // Another row already owns this key, so the post just
                    // created is a duplicate. Remove it and count it as a skip.
                    $this->discardPost($id);
                    $skipped++;
                    continue;
                }
            }

            if ($collection_id === null) {
                $collection_id = service('repository.set')->create(new Set([
                    'name' => $csv->filename,
                    'description' => 'Import',
                    'view' => 'data',
                    'featured' => false
                ]));
            }

            service('repository.set')->addPostToSet($collection_id, $id);
            $processed++;
        }

        // A run that only skipped is a success: every row was already present.
        $new_status = ($processed > 0 || $skipped > 0) ? 'SUCCESS' : 'FAILED';
        $csv->setState([
            'status' => $new_status,
            'collection_id' => $collection_id,
            'processed' => $processed,
            'skipped' => $skipped,
            'errors' => $errors
        ]);

        service('repository.csv')->update($csv);
    }
}
