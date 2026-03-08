<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseBalanceGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_create_fails_when_amount_exceeds_balance(): void
    {
        $user = User::factory()->create();
        $headers = $this->authHeaders($user);

        $category = Category::query()->create([
            'name' => 'Belanja',
            'type' => 'expense',
            'idUser' => $user->idUser,
        ]);

        $this->withHeaders($headers)->postJson('/api/transactions', [
            'type' => 'income',
            'amount' => 100000,
            'idCategory' => $category->idCategory,
        ])->assertCreated();

        $response = $this->withHeaders($headers)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 150000,
            'idCategory' => $category->idCategory,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Insufficient balance for this expense');
    }

    public function test_expense_update_fails_when_amount_exceeds_balance(): void
    {
        $user = User::factory()->create();
        $headers = $this->authHeaders($user);

        $category = Category::query()->create([
            'name' => 'Belanja',
            'type' => 'expense',
            'idUser' => $user->idUser,
        ]);

        Transaction::query()->create([
            'idUser' => $user->idUser,
            'idCategory' => $category->idCategory,
            'type' => 'income',
            'amount' => 100000,
            'date' => now(),
        ]);

        $expense = Transaction::query()->create([
            'idUser' => $user->idUser,
            'idCategory' => $category->idCategory,
            'type' => 'expense',
            'amount' => 20000,
            'date' => now(),
        ]);

        $response = $this->withHeaders($headers)->putJson('/api/transactions/' . $expense->idTransaction, [
            'amount' => 120000,
            'type' => 'expense',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Insufficient balance for this expense');
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
