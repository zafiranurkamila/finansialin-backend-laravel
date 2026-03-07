<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_transaction_date_only_is_parsed_as_utc_midnight(): void
    {
        $user = User::query()->create([
            'email' => 'parity-user@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Parity User',
        ]);

        $headers = $this->authHeaders($user);

        $response = $this->withHeaders($headers)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 100000,
            'description' => 'Test UTC parsing',
            'date' => '2026-01-05',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('type', 'expense');
        $response->assertJsonPath('amount', '100000.00');

        $date = (string) $response->json('date');
        $this->assertStringStartsWith('2026-01-05T00:00:00', $date);
    }

    public function test_budget_filter_normalizes_yearly_period_to_year(): void
    {
        $user = User::query()->create([
            'email' => 'budget-parity@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Budget Parity',
        ]);

        Budget::query()->create([
            'idUser' => $user->idUser,
            'period' => 'year',
            'periodStart' => '2026-01-01 00:00:00',
            'periodEnd' => '2026-12-31 23:59:59',
            'amount' => 1200000,
        ]);

        Budget::query()->create([
            'idUser' => $user->idUser,
            'period' => 'monthly',
            'periodStart' => '2026-06-01 00:00:00',
            'periodEnd' => '2026-06-30 23:59:59',
            'amount' => 100000,
        ]);

        $headers = $this->authHeaders($user);

        $response = $this->withHeaders($headers)->getJson('/api/budgets/filter?period=yearly&date=2026-06-11');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.period', 'year');
        $response->assertJsonPath('0.amount', '1200000.00');
    }

    public function test_transactions_by_month_returns_only_requested_utc_month(): void
    {
        $user = User::query()->create([
            'email' => 'month-parity@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Month Parity',
        ]);

        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 150000,
            'description' => 'February tx',
            'date' => '2026-02-01',
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 250000,
            'description' => 'March tx',
            'date' => '2026-03-01',
        ])->assertCreated();

        $response = $this->withHeaders($headers)->getJson('/api/transactions/month/2026/2');

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonPath('0.description', 'February tx');
    }

    public function test_expense_reaching_eighty_percent_budget_creates_warning_notification(): void
    {
        $user = User::query()->create([
            'email' => 'warning-parity@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Warning Parity',
        ]);

        $category = Category::query()->create([
            'name' => 'Makan',
            'idUser' => $user->idUser,
        ]);

        Budget::query()->create([
            'idUser' => $user->idUser,
            'idCategory' => $category->idCategory,
            'period' => 'monthly',
            'periodStart' => '2026-01-01 00:00:00',
            'periodEnd' => '2026-01-31 23:59:59',
            'amount' => 100000,
        ]);

        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)->postJson('/api/transactions', [
            'idCategory' => $category->idCategory,
            'type' => 'expense',
            'amount' => 80000,
            'description' => 'Belanja bulanan',
            'date' => '2026-01-15',
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'idUser' => $user->idUser,
            'type' => 'BUDGET_WARNING',
            'read' => 0,
        ]);
    }

    public function test_notifications_endpoints_mark_read_and_count_unread(): void
    {
        $user = User::query()->create([
            'email' => 'notif-parity@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Notif Parity',
        ]);

        $headers = $this->authHeaders($user);

        $first = $this->withHeaders($headers)->postJson('/api/transactions', [
            'type' => 'income',
            'amount' => 100000,
            'description' => 'Gaji',
            'date' => '2026-01-10',
        ]);
        $first->assertCreated();

        $second = $this->withHeaders($headers)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 25000,
            'description' => 'Kopi',
            'date' => '2026-01-11',
        ]);
        $second->assertCreated();

        $countResponse = $this->withHeaders($headers)->getJson('/api/notifications/unread/count');
        $countResponse->assertOk();
        $countResponse->assertJsonPath('count', 2);

        $unread = $this->withHeaders($headers)->getJson('/api/notifications/unread');
        $unread->assertOk();
        $unread->assertJsonCount(2);

        $idNotification = (int) $unread->json('0.idNotification');

        $this->withHeaders($headers)
            ->patchJson('/api/notifications/' . $idNotification . '/read')
            ->assertOk();

        $countAfterSingle = $this->withHeaders($headers)->getJson('/api/notifications/unread/count');
        $countAfterSingle->assertOk();
        $countAfterSingle->assertJsonPath('count', 1);

        $this->withHeaders($headers)
            ->patchJson('/api/notifications/read-all')
            ->assertOk();

        $countAfterAll = $this->withHeaders($headers)->getJson('/api/notifications/unread/count');
        $countAfterAll->assertOk();
        $countAfterAll->assertJsonPath('count', 0);
    }

    public function test_budget_goals_returns_totals_for_normalized_period(): void
    {
        $user = User::query()->create([
            'email' => 'goals-parity@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Goals Parity',
        ]);

        $category = Category::query()->create([
            'name' => 'Transport',
            'idUser' => $user->idUser,
        ]);

        Budget::query()->create([
            'idUser' => $user->idUser,
            'idCategory' => $category->idCategory,
            'period' => 'year',
            'periodStart' => '2026-01-01 00:00:00',
            'periodEnd' => '2026-12-31 23:59:59',
            'amount' => 1000000,
        ]);

        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)->postJson('/api/transactions', [
            'idCategory' => $category->idCategory,
            'type' => 'expense',
            'amount' => 250000,
            'description' => 'Bus and fuel',
            'date' => '2026-06-01',
        ])->assertCreated();

        $response = $this->withHeaders($headers)
            ->getJson('/api/budgets/goals?period=yearly&date=2026-06-11&type=expense');

        $response->assertOk();
        $response->assertJsonPath('period.period', 'year');
        $response->assertJsonPath('totals.totalBudget', 1000000);
        $response->assertJsonPath('totals.totalSpent', 250000);
    }

    public function test_transactions_by_month_invalid_period_returns_bad_request(): void
    {
        $user = User::query()->create([
            'email' => 'invalid-month@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Invalid Month',
        ]);

        $headers = $this->authHeaders($user);

        $response = $this->withHeaders($headers)->getJson('/api/transactions/month/2026/13');

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Invalid period');
    }

    public function test_transaction_create_rejects_foreign_user_category_with_forbidden(): void
    {
        $owner = User::query()->create([
            'email' => 'owner-category@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Owner Category',
        ]);

        $otherUser = User::query()->create([
            'email' => 'other-user@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Other User',
        ]);

        $foreignCategory = Category::query()->create([
            'name' => 'Private Owner Category',
            'idUser' => $owner->idUser,
        ]);

        $headers = $this->authHeaders($otherUser);

        $response = $this->withHeaders($headers)->postJson('/api/transactions', [
            'idCategory' => $foreignCategory->idCategory,
            'type' => 'expense',
            'amount' => 50000,
            'description' => 'Should be forbidden',
            'date' => '2026-02-02',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Not allowed');
    }

    public function test_refresh_with_invalid_token_returns_unauthorized(): void
    {
        $response = $this->postJson('/api/auth/refresh', [
            'refreshToken' => 'invalid-refresh-token',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Invalid refresh token');
    }

    public function test_update_profile_duplicate_email_returns_bad_request(): void
    {
        $userA = User::query()->create([
            'email' => 'user-a@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'User A',
        ]);

        $userB = User::query()->create([
            'email' => 'user-b@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'User B',
        ]);

        $headers = $this->authHeaders($userA);

        $response = $this->withHeaders($headers)->putJson('/api/users/profile', [
            'email' => $userB->email,
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Email already in use');
    }

    public function test_reset_password_wrong_old_password_returns_unauthorized(): void
    {
        $user = User::query()->create([
            'email' => 'wrong-old-pass@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Wrong Old Pass',
        ]);

        $headers = $this->authHeaders($user);

        $response = $this->withHeaders($headers)->patchJson('/api/users/password', [
            'oldPassword' => 'not-the-old-one',
            'newPassword' => 'new-secret-999',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Old password is incorrect');
    }

    public function test_reset_password_success_creates_notification(): void
    {
        $user = User::query()->create([
            'email' => 'reset-ok@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Reset Ok',
        ]);

        $headers = $this->authHeaders($user);

        $response = $this->withHeaders($headers)->patchJson('/api/users/password', [
            'oldPassword' => 'secret123',
            'newPassword' => 'new-secret-999',
        ]);

        $response->assertOk();
        $response->assertJsonPath('message', 'Password updated successfully');

        $this->assertDatabaseHas('notifications', [
            'idUser' => $user->idUser,
            'type' => 'PASSWORD_RESET',
            'read' => 0,
        ]);
    }

    /**
     * Issue an access token and return a valid API Authorization header.
     */
    private function authHeaders(User $user): array
    {
        $token = AuthToken::issue($user, 'access', now()->addMinutes(30));

        return [
            'Authorization' => 'Bearer ' . $token['plain'],
            'Accept' => 'application/json',
        ];
    }
}
