<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PredictiveBudgetingTest extends TestCase
{
    use RefreshDatabase;

    public function test_predictive_endpoint_returns_30_60_90_trends_and_warnings(): void
    {
        $user = User::factory()->create();

        $category = Category::query()->create([
            'name' => 'Food & Drinks',
            'type' => 'expense',
            'idUser' => $user->idUser,
        ]);

        Budget::query()->create([
            'idUser' => $user->idUser,
            'idCategory' => $category->idCategory,
            'period' => 'monthly',
            'periodStart' => now()->startOfMonth(),
            'periodEnd' => now()->endOfMonth(),
            'amount' => 300000,
        ]);

        Transaction::query()->create([
            'idUser' => $user->idUser,
            'idCategory' => $category->idCategory,
            'type' => 'expense',
            'amount' => 600000,
            'description' => 'Food spending burst',
            'date' => now()->subDays(10),
        ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->getJson('/api/budgets/predictive');

        $response->assertOk();
        $response->assertJsonCount(3, 'trends');
        $response->assertJsonStructure([
            'summary' => ['activeBudgetCount', 'totalBudget'],
            'trends' => [
                '*' => ['windowDays', 'projectedMonthExpense', 'projectedUtilizationPercent', 'riskLevel'],
            ],
            'categoryWarnings',
        ]);
    }

    private function authHeaders(User $user): array
    {
        $token = AuthToken::issue($user, 'access', now()->addHour());

        return [
            'Authorization' => 'Bearer ' . $token['plain'],
            'Accept' => 'application/json',
        ];
    }
}
