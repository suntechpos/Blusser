<?php

namespace App\Modules\Patients\Console;

use App\Modules\Patients\Services\CeoGrantBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BackfillCeoPermissions extends Command
{
    protected $signature = 'patients:backfill-ceo {--apply : Apply missing grants; without this flag only preview counts}';

    protected $description = 'Owner-only verified CEO permission backfill with deployment provenance';

    public function handle(CeoGrantBackfill $backfill): int
    {
        try {
            $count = $backfill->run(DB::connection('migration'), (bool) $this->option('apply'));
            $this->info(($this->option('apply') ? 'Applied' : 'Preview').': '.$count.' missing grants.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Backfill unavailable. Verify owner connection, deployed Patients schema and catalog.');

            return self::FAILURE;
        }
    }
}
