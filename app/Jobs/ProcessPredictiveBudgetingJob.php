<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\Transaction;
use App\Models\Budget;
use App\Models\Salary;
use App\Models\UserNotification;
use Carbon\Carbon;

class ProcessPredictiveBudgetingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $userId;

    /**
     * Create a new job instance.
     */
    public function __construct($userId)
    {
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $userId = $this->userId;
        
        // a. Aggregated daily expenses past 90 days
        $startDate = Carbon::now()->subDays(90)->format('Y-m-d');
        
        $expenses = Transaction::select(
                DB::raw('DATE(date) as date'),
                DB::raw('SUM(amount) as amount')
            )
            ->where('idUser', $userId)
            ->where('type', 'expense')
            ->where('date', '>=', $startDate)
            ->groupBy(DB::raw('DATE(date)'))
            ->get();

        // b. Active monthly budgets
        $currentMonthPeriod = gmdate('Y-m'); // e.g. '2026-04'
        $totalBudget = Budget::where('idUser', $userId)
            ->where('period', $currentMonthPeriod)
            ->sum('amount');

        // c. Payday details
        $salary = Salary::where('idUser', $userId)->orderBy('salaryDate', 'desc')->first();
        $paydayDate = $salary && $salary->salaryDate ? Carbon::parse($salary->salaryDate)->day : 1; // Default to 1st if no salary

        // Structure the data for Python AI Service
        $payload = [
            'user_id' => $userId,
            'budget' => (float) $totalBudget,
            'payday_date' => $paydayDate,
            'expenses' => $expenses->toArray(),
        ];

        // Send request to Python Service
        try {
            $response = Http::timeout(15)->post('http://127.0.0.1:8000/predict/budget', $payload);

            if ($response->successful()) {
                $responseData = $response->json();

                // If overspending is predicted, trigger a notification
                if (isset($responseData['is_overspending']) && $responseData['is_overspending']) {
                    $warningMessage = $responseData['warning_message'] ?? 'Warning: Projected expenses exceed the monthly budget.';
                    
                    UserNotification::create([
                        'idUser' => $userId,
                        'type' => 'Peringatan Predictive Budgeting',
                        'message' => $warningMessage,
                        'read' => false,
                        'createdAt' => Carbon::now(),
                    ]);
                }
            } else {
                Log::error('Predictive Budgeting AI Service Error Response', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'user_id' => $userId,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to connect to Predictive Budgeting AI Service', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
        }
    }
}
