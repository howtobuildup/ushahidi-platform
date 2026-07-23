<?php

namespace Ushahidi\Modules\V3\Jobs;

use RuntimeException;
use Illuminate\Support\Str;
use Ushahidi\Core\Tool\Job;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Ushahidi\Core\Entity\ExportJob;
use Ushahidi\Core\Entity\ExportBatch;
use Illuminate\Support\Facades\Storage;
use Ushahidi\Core\Concerns\RecordsExportJobFailure;
use Ushahidi\Contracts\Repository\Entity\ExportJobRepository;
use Ushahidi\Contracts\Repository\Entity\ExportBatchRepository;

class CombineExportedPostBatchesJob extends Job
{
    use RecordsExportJobFailure;

    protected $jobId;

    protected $csvPrefix;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($jobId)
    {
        $this->jobId = $jobId;
        $this->csvPrefix = config('media.csv_final_prefix', 'csv');
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(
        ExportJobRepository $exportJobRepo,
        ExportBatchRepository $exportBatchRepo
    ) {
        // Load job
        $job = $exportJobRepo->get($this->jobId);

        if ($job->isCombineBatchesDone()) {
            Log::debug('Job already completed', ['jobId' => $this->jobId]);
            // All done here
            return;
        }

        // Check if all batches exported
        if (! $exportJobRepo->areBatchesFinished($this->jobId)) {
            Log::debug('Batches not finished, requeueing', ['jobId' => $this->jobId]);
            // Batches aren't done yet, wait 5 minutes and try again
            dispatch(new self($this->jobId));
            // All done
            return;
        }

        // Load batches
        $batches = $exportBatchRepo->getByJobId($this->jobId, ExportBatch::STATUS_COMPLETED);
        // Get just filenames
        $fileNames = $batches
            ->sortBy('batch_number')
            ->sortByDesc('has_header')
            ->pluck('filename')
            ->all();

        if (! $this->doAllFilesExist($fileNames)) {
            Log::warning('Some files in export job do not exist', ['jobId' => $this->jobId]);
            throw new RuntimeException('Some files in export job do not exist');
        }

        // Combine the files
        $destinationFile = $this->combineFiles($fileNames);

        // Clean up partial files
        Storage::delete($fileNames);

        // Set status = completed
        $job->setState([
            'url' => $destinationFile, // No longer actually saving a URL, we can format it when it goes to the API
            'status' => ExportJob::STATUS_EXPORTED_TO_CDN,
        ]);
        $exportJobRepo->update($job);
    }

    protected function combineFiles(array $fileNames)
    {
        // Create destination filename
        $destinationFileName = Carbon::now()->format('Ymd').'-'.Str::random(40).'.csv';
        $uploadedFileName = trim($this->csvPrefix, '/').'/'.$destinationFileName;
        $destinationStream = tmpfile();
        if (! is_resource($destinationStream)) {
            throw new RuntimeException('Unable to create the combined export stream');
        }

        try {
            foreach ($fileNames as $file) {
                Log::debug('Processing file', compact('file'));
                $stream = Storage::readStream($file);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Unable to read export batch: '.$file);
                }

                try {
                    if (stream_copy_to_stream($stream, $destinationStream) === false) {
                        throw new RuntimeException('Unable to combine export batch: '.$file);
                    }
                } finally {
                    fclose($stream);
                }
            }

            rewind($destinationStream);
            if (! Storage::put($uploadedFileName, $destinationStream)) {
                throw new RuntimeException('Unable to store the combined export');
            }
        } finally {
            fclose($destinationStream);
        }

        Log::debug('Uploaded combined file', compact('uploadedFileName'));

        return $uploadedFileName;
    }

    public function doAllFilesExist(array $fileNames)
    {
        foreach ($fileNames as $index => $file) {
            if (! Storage::exists($file)) {
                Log::warning('File does not exist', ['file' => $file, 'index' => $index]);

                return false;
            }
        }

        return true;
    }
}
