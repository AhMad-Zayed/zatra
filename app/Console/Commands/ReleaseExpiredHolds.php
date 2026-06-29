<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\InventoryLedger;
use Illuminate\Support\Facades\DB;

class ReleaseExpiredHolds extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:release-expired-holds';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Releases expired inventory holds';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        DB::transaction(function () {
            InventoryLedger::where('type', 'hold')
                ->where('expires_at', '<=', now())
                ->update(['type' => 'expired']);
        });

        $this->info('Expired holds released successfully.');
    }
}
