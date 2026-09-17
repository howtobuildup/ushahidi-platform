<?php

/**
 * Ushahidi CSV import claim trait
 *
 * Gives a controller one method, `claimCsvImport($id)`, which marks an upload
 * as in progress and reports whether this caller won the race.
 *
 * There are two entry points to the importer, api/v3/csv/{id}/import and the
 * v5 controller, and both run the import inline within the request. A client
 * that gives up and retries - which is exactly what a proxy read timeout
 * invites - would otherwise start a second pass over the same file while the
 * first is still writing posts, appending a duplicate of every row.
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @package    Ushahidi\Application
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

namespace Ushahidi\Core\Concerns;

use Illuminate\Support\Facades\DB;

trait ClaimsCsvImport
{
    /**
     * Mark an upload as in progress, but only if it is not already.
     *
     * One conditional UPDATE, so two concurrent requests cannot both win: the
     * second matches no rows and is turned away.
     *
     * A run that died without clearing its status - the request was severed, or
     * PHP hit its time limit - would otherwise block the file forever, so a
     * claim that has gone quiet for longer than the import is allowed to run
     * can be taken over. 600s matches set_time_limit in both controllers.
     *
     * @param  int $id csv upload id
     * @return bool true if the caller may proceed with the import
     */
    protected function claimCsvImport(int $id): bool
    {
        $stale_before = time() - 600;

        return DB::table('csv')
            ->where('id', $id)
            ->where(function ($query) use ($stale_before) {
                $query->whereNull('status')
                    ->orWhere('status', '!=', 'PENDING')
                    ->orWhereNull('updated')
                    ->orWhere('updated', '<', $stale_before);
            })
            ->update(['status' => 'PENDING', 'updated' => time()]) > 0;
    }
}
