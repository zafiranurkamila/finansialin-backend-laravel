<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Jobs\ProcessPredictiveBudgetingJob;
use Carbon\Carbon;

class TriggerPredictiveBudgeting extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:trigger-predictive-budgeting';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Trigger predictive budgeting job for active users';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $thirtyDaysAgo = Carbon::now()->subDays(30)->format('Y-m-d');
        
        $this->info('Starting to dispatch predictive budgeting jobs...');

        // Fetch users who have at least 1 transaction in the last 30 days
        User::whereHas('transactions', function($query) use ($thirtyDaysAgo) {
            $query->where('date', '>=', $thirtyDaysAgo);
        })->chunk(100, function ($users) {
            foreach ($users as $user) {
                ProcessPredictiveBudgetingJob::dispatch($user->idUser);
            }
        });

        $this->info('Successfully dispatched predictive budgeting jobs for active users.');
    }
}
