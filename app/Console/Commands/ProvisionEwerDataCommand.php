<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Ushahidi\Modules\V5\Models\Survey;
use Ushahidi\Modules\V5\Models\User;

class ProvisionEwerDataCommand extends Command
{
    protected $signature = 'saferworld:provision-ewer
        {xlsform : Path to the NAGAASHO XLSForm workbook}
        {csv : Path to the Kobo submissions CSV}
        {--name=NAGAASHO EWER : Survey name}
        {--user-id= : Default owner user ID for historical submissions}
        {--user-email= : Default owner email for historical submissions}
        {--user-map= : JSON file mapping Field Monitor Code values to user IDs}
        {--mapping= : JSON file mapping CSV headers to survey field keys}
        {--roles=admin,field_monitor : Roles allowed to submit the survey}
        {--status=published : Post status for imported submissions}
        {--limit=0 : Maximum number of CSV rows to process}
        {--dry-run : Validate without importing submissions}
        {--stop-on-error : Stop when the first submission fails}';

    protected $description = 'Provision the NAGAASHO survey and historical Kobo data idempotently';

    public function handle()
    {
        $xlsform = (string) $this->argument('xlsform');
        $csv = (string) $this->argument('csv');
        $name = trim((string) $this->option('name'));
        $userId = $this->option('user-id');
        $userEmail = trim((string) $this->option('user-email'));
        $userMap = $this->option('user-map');

        if (!$userId && !$userEmail && !$userMap) {
            $this->error(
                'Provide --user-id, --user-email, --user-map, or a combination for post ownership.'
            );
            return 1;
        }
        if ($userEmail !== '') {
            $user = User::where('email', $userEmail)->first();
            if (!$user) {
                $this->error("User '{$userEmail}' does not exist in this environment.");
                return 1;
            }
            $userId = (int) $user->id;
        }
        if ($userId && !User::where('id', (int) $userId)->exists()) {
            $this->error("User {$userId} does not exist in this environment.");
            return 1;
        }

        $survey = Survey::where('name', $name)->orderByDesc('id')->first();
        if (!$survey) {
            $this->info("Survey '{$name}' does not exist. Validating the XLSForm.");
            $validationResult = $this->call('saferworld:import-xlsform', [
                'file' => $xlsform,
                '--name' => $name,
                '--roles' => (string) $this->option('roles'),
                '--dry-run' => true,
            ]);
            if ($validationResult !== 0) {
                return $validationResult;
            }

            if ($this->option('dry-run')) {
                $this->warn(
                    'Submission validation requires the survey schema. '
                    . 'Run without --dry-run to create it and import the CSV.'
                );
                return 0;
            }

            $createResult = $this->call('saferworld:import-xlsform', [
                'file' => $xlsform,
                '--name' => $name,
                '--roles' => (string) $this->option('roles'),
            ]);
            if ($createResult !== 0) {
                return $createResult;
            }

            $survey = Survey::where('name', $name)->orderByDesc('id')->first();
            if (!$survey) {
                $this->error('The survey command completed but the survey could not be found.');
                return 1;
            }
        } else {
            $this->info("Reusing survey '{$name}' with ID {$survey->id}.");
        }

        $arguments = [
            'file' => $csv,
            '--form-id' => (int) $survey->id,
            '--status' => (string) $this->option('status'),
            '--limit' => max(0, (int) $this->option('limit')),
            '--dry-run' => (bool) $this->option('dry-run'),
            '--stop-on-error' => (bool) $this->option('stop-on-error'),
        ];

        if ($userId) {
            $arguments['--user-id'] = (int) $userId;
        }
        if ($userMap) {
            $arguments['--user-map'] = (string) $userMap;
        }
        if ($this->option('mapping')) {
            $arguments['--mapping'] = (string) $this->option('mapping');
        }

        $result = $this->call('saferworld:import-kobo', $arguments);
        if ($result === 0 && !$this->option('dry-run')) {
            $this->info(
                "EWER provisioning complete for '{$name}'. "
                . 'Existing Kobo UUIDs were left unchanged.'
            );
        }

        return $result;
    }
}
