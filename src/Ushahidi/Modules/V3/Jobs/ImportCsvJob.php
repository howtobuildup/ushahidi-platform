<?php

namespace Ushahidi\Modules\V3\Jobs;

use Illuminate\Support\Facades\Log;
use Ushahidi\Core\Entity\CSV;
use Ushahidi\Core\Tool\Job;

/**
 * Imports an uploaded CSV in the background.
 *
 * The import used to run inline within the HTTP request, which took tens of
 * seconds for a few hundred rows. That left the browser on a spinner with no
 * progress, and left a long-lived request exposed to anything that retries:
 * nginx-ingress retries on error or timeout by default, and a second pass over
 * the same file used to append a duplicate of every row.
 *
 * Queueing it means the request returns immediately and the client polls the
 * csv record for status, which the import results screen already does.
 */
class ImportCsvJob extends Job
{
    /**
     * Uploaded csv record to import.
     *
     * @var int
     */
    protected $csvId;

    /**
     * Operator who started the import.
     *
     * The worker has no authenticated user of its own, so the id is carried
     * across and the session restored, letting the import authorise and
     * validate exactly as it did when it ran inside the request.
     *
     * @var int|null
     */
    protected $userId;

    public function __construct($csvId, $userId = null)
    {
        $this->csvId = $csvId;
        $this->userId = $userId;
    }

    public function handle()
    {
        // Support all line endings without manually specifying it
        // (primarily added because of OS9 line endings which do not work by default)
        ini_set('auto_detect_line_endings', 1);

        if ($this->userId) {
            service('session')->setUser((int) $this->userId);
        }

        $csv = service('repository.csv')->get($this->csvId);

        $fs = service('tool.filesystem');
        $reader = service('filereader.csv');
        $transformer = service('transformer.csv');

        $file = new \SplTempFileObject();
        $file->fwrite($fs->read($csv->filename));

        $records = $reader->process($file);

        $transformer->setColumnNames($csv->columns ?: []);
        $transformer->setMap($csv->maps_to ?: []);
        $transformer->setFixedValues($csv->fixed ?: []);

        // Run through the usecase rather than emitting straight to the
        // listener, so authorisation and per-row validation stay as they were.
        service('factory.usecase')
            ->get('posts', 'import')
            ->setPayload($records)
            ->setCSV($csv)
            ->setTransformer($transformer)
            ->interact();
    }

    /**
     * Leave the upload in a state the operator can act on.
     *
     * Without this the record would sit at PENDING forever: the results screen
     * would poll indefinitely, and the claim guard would refuse another attempt
     * until the claim went stale.
     */
    public function failed(\Throwable $e)
    {
        Log::error('CSV import failed', [
            'csvId' => $this->csvId,
            'error' => $e->getMessage(),
        ]);

        try {
            $csv = service('repository.csv')->get($this->csvId);
            $csv->setState([
                'status' => 'FAILED',
                'errors' => substr($e->getMessage(), 0, 255),
            ]);
            service('repository.csv')->update($csv);
        } catch (\Exception $inner) {
            Log::error('Could not mark CSV import as failed', [
                'csvId' => $this->csvId,
                'error' => $inner->getMessage(),
            ]);
        }
    }
}
