<?php

namespace Ushahidi\Modules\V5\Http\Controllers;

use Illuminate\Http\Request;
use Ushahidi\Modules\V5\Actions\Export\Queries\FetchExportJobByIdQuery;
use Ushahidi\Modules\V5\Actions\Export\Queries\FetchExportJobQuery;
use Ushahidi\Modules\V5\Http\Resources\Export\ExportJobResource;
use Ushahidi\Modules\V5\Http\Resources\Export\ExportJobCollection;
use Ushahidi\Modules\V5\Actions\Export\Commands\CreateExportJobCommand;
use Ushahidi\Modules\V5\Actions\Export\Commands\UpdateExportJobCommand;
use Ushahidi\Modules\V5\Actions\Export\Commands\DeleteExportJobCommand;
use Ushahidi\Modules\V5\Requests\ExportJobRequest;
use Ushahidi\Modules\V5\Models\ExportJob;
use Ushahidi\Core\Exception\NotFoundException;
use Ushahidi\Core\Concerns\FormatRackspaceURL;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExportJobController extends V5Controller
{
    use FormatRackspaceURL;


    /**
     * Display the specified resource.
     *
     * @param integer $id
     * @return mixed
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function show(int $id)
    {
        $export_job = $this->queryBus->handle(new FetchExportJobByIdQuery($id));
        $this->authorize('show', $export_job);
        return new ExportJobResource($export_job);
    } //end show()

    /**
     * Download the generated export file through the API.
     *
     * This avoids exposing /storage paths directly, which can fail behind
     * production ingress rules even when the file exists on the configured disk.
     *
     * @param integer $id
     * @return mixed
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function download(int $id)
    {
        $export_job = $this->queryBus->handle(new FetchExportJobByIdQuery($id));
        $this->authorize('show', $export_job);

        if (strtoupper((string) $export_job->status) !== 'SUCCESS') {
            return response()->json([
                'error' => 409,
                'message' => 'The exported CSV file is not ready yet.',
            ], 409);
        }

        if (!$export_job->url) {
            return response()->json([
                'error' => 409,
                'message' => 'The exported CSV file is not ready yet.',
            ], 409);
        }

        if (filter_var($export_job->url, FILTER_VALIDATE_URL)) {
            return redirect()->away($export_job->url);
        }

        $path = $this->normalizeExportPath($export_job->url);
        foreach ($this->exportStorageDisks() as $disk) {
            $download = $this->streamExportFromDisk($disk, $path, $id);
            if ($download) {
                return $download;
            }
        }

        $fallback_url = $this->formatUrl($path);
        if (
            $fallback_url &&
            filter_var($fallback_url, FILTER_VALIDATE_URL) &&
            !$this->isLocalStorageUrl($fallback_url)
        ) {
            return redirect()->away($fallback_url);
        }

        return self::make404('The exported CSV file could not be found.');
    } //end download()

    private function streamExportFromDisk($disk, $path, $id)
    {
        try {
            $storage = Storage::disk($disk);
            $size = $storage->size($path);
            if ($size <= 0) {
                return null;
            }

            $stream = $storage->readStream($path);
            if (is_resource($stream)) {
                $filename = basename($path) ?: 'export-' . $id . '.csv';
                return response()->streamDownload(function () use ($stream) {
                    try {
                        fpassthru($stream);
                    } finally {
                        fclose($stream);
                    }
                }, $filename, [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                    'Content-Length' => $size,
                ]);
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    private function exportStorageDisks()
    {
        return array_values(array_unique(array_filter([
            config('filesystems.default'),
            config('filesystems.cloud'),
            'public',
            'local',
        ])));
    }

    private function normalizeExportPath($path)
    {
        $path = rawurldecode((string) $path);
        $url_path = parse_url($path, PHP_URL_PATH);
        if ($url_path) {
            $path = $url_path;
        }
        $path = ltrim($path, '/');
        if (strpos($path, 'storage/') === 0) {
            $path = substr($path, strlen('storage/'));
        }
        return $path;
    }

    private function isLocalStorageUrl($url)
    {
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) && strpos(ltrim($path, '/'), 'storage/') === 0;
    }



    /**
     * Display the specified resource.
     *
     * @return ExportJobCollection
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index(Request $request)
    {
        $this->authorize('index', ExportJob::class);
        $export_jobs = $this->queryBus->handle(FetchExportJobQuery::FromRequest($request));
        return new ExportJobCollection($export_jobs);
    } //end index()


    /**
     * Create new ExportJob.
     *
     * @param ExportJobRequest $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function store(ExportJobRequest $request)
    {
        $command = CreateExportJobCommand::fromRequest($request);
        $new_export_job = new ExportJob($command->getExportJobEntity()->asArray());
        $this->authorize('store', $new_export_job);
        return $this->show($this->commandBus->handle($command));
    } //end store()

     /**
     * update  ExportJob.
     *
     * @param int id
     * @param ExportJobRequest $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function update(int $id, ExportJobRequest $request)
    {
        $old_export_job = $this->queryBus->handle(new FetchExportJobByIdQuery($id));
        $command = UpdateExportJobCommand::fromRequest($id, $request, $old_export_job);
        $new_export_job = new ExportJob($command->getExportJobEntity()->asArray());
        $this->authorize('update', $new_export_job);
        $this->commandBus->handle($command);
        return $this->show($id);
    }// end update

     /**
     * Create new ExportJob.
     *
     * @param int id
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function delete(int $id)
    {
        try {
            $export_job = $this->queryBus->handle(new FetchExportJobByIdQuery($id));
        } catch (NotFoundException $e) {
            $export_job = new ExportJob();
        }
        $this->authorize('delete', $export_job);
        $this->commandBus->handle(new DeleteExportJobCommand($id));
        return $this->deleteResponse($id);
    }// end delete
} //end class
