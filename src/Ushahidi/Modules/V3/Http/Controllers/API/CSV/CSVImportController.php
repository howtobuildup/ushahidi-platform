<?php

namespace Ushahidi\Modules\V3\Http\Controllers\API\CSV;

use Illuminate\Http\Request;
use Ushahidi\Modules\V3\Http\Controllers\RESTController;
use Ushahidi\Core\Concerns\ClaimsCsvImport;
use Ushahidi\Modules\V3\Jobs\ImportCsvJob;

/**
 * Ushahidi API CSV Import
 *
 * @author     Ushahidi Team <team@ushahidi.com>
 * @copyright  2013 Ushahidi
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */
class CSVImportController extends RestController
{
    use ClaimsCsvImport;

    protected function getResource()
    {
        return 'posts';
    }

    public function store(Request $request, $id = null)
    {
        // Claim the upload before doing anything else, so a retried request
        // cannot start a second pass over a file that is already importing.
        if (!$this->claimCsvImport($id)) {
            return response()->json([
                'error' => 409,
                'message' => 'This file is already being imported. '
                    . 'Check the import results before trying again.',
            ], 409);
        }

        // Confirm the upload exists before queueing work against it.
        $csv = service('repository.csv')->get($id);

        // Queued rather than run here: a few hundred rows takes tens of
        // seconds, which leaves the browser on a spinner and the request
        // exposed to anything that retries it. The results screen already
        // polls the csv record for status.
        $user = service('session')->getUser();

        dispatch(new ImportCsvJob($csv->id, $user ? $user->getId() : null));

        return response()->json([
            'result' => [
                'import' => 'PENDING',
                'id' => $csv->id,
            ],
        ], 200);
    }
}
