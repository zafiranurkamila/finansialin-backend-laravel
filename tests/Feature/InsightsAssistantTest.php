<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsightsAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_assistant_returns_summary_and_quick_prompts(): void
    {
        $user = User::factory()->create([
            'email' => 'assistant-user@example.com',
        ]);

        $expenseCategory = Category::query()->create([
            'name' => 'Food',
            'type' => 'expense',
            'idUser' => $user->idUser,
        ]);

        Transaction::query()->create([
            'idUser' => $user->idUser,
            'idCategory' => $expenseCategory->idCategory,
            'type' => 'income',
            'amount' => 2000000,
            'date' => now()->subDays(5),
        ]);

        Transaction::query()->create([
            'idUser' => $user->idUser,
            'idCategory' => $expenseCategory->idCategory,
            'type' => 'expense',
            'amount' => 750000,
            'date' => now()->subDays(2),
        ]);

        $response = $this
            ->withHeaders($this->authHeaders($user))
            ->getJson('/api/insights/assistant?prompt=summary');

        $response->assertOk();
        $response->assertJsonStructure([
            'summary' => [
                'periodDays',
                'income',
                'expense',
                'net',
                'savingsRate',
                'topExpenseCategory',
                'topExpenseAmount',
                'projectedMonthlyExpense',
                'activeBudgetWarnings',
            ],
            'assistantReply',
            'quickPrompts' => [
                '*' => ['key', 'label'],
            ],
        ]);

        $response->assertJsonPath('summary.topExpenseCategory', 'Food');
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
