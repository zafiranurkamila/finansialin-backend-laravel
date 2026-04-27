<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class FrontendContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_fields_used_by_frontend(): void
    {
        $password = 'secret123';
        $user = User::query()->create([
            'email' => 'contract-login@example.com',
            'phone' => '+6281000000001',
            'password' => bcrypt($password),
            'name' => 'Contract Login',
            'phoneVerifiedAt' => now(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'accessToken',
            'refreshToken',
            'access_token',
            'refresh_token',
            'expiresIn',
            'user' => ['id', 'idUser', 'email', 'name', 'createdAt'],
        ]);
    }

    public function test_register_requires_otp_verification_before_user_creation(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'contract-register@example.com',
            'phone' => '+628111111111',
            'password' => 'secret123',
            'name' => 'Contract Register',
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('requiresRegistrationVerification', true);
        $response->assertJsonStructure([
            'message',
            'requiresRegistrationVerification',
            'registrationVerification' => ['required', 'email', 'expiresAt'],
            'debugOtp',
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'contract-register@example.com',
        ]);

        $verify = $this->postJson('/api/auth/register/verify', [
            'email' => 'contract-register@example.com',
            'code' => (string) $response->json('debugOtp'),
        ]);

        $verify->assertOk();
        $verify->assertJsonStructure([
            'accessToken',
            'refreshToken',
            'access_token',
            'refresh_token',
            'expiresIn',
            'message',
            'user' => ['id', 'idUser', 'email', 'phone', 'name', 'createdAt'],
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'contract-register@example.com',
            'phone' => '+628111111111',
        ]);
    }

    public function test_register_normalizes_phone_number_format_after_otp_verification(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'contract-phone-normalize@example.com',
            'phone' => '0812-3456-7890',
            'password' => 'secret123',
            'name' => 'Contract Phone Normalize',
        ]);

        $response->assertStatus(202);

        $verify = $this->postJson('/api/auth/register/verify', [
            'email' => 'contract-phone-normalize@example.com',
            'code' => (string) $response->json('debugOtp'),
        ]);

        $verify->assertOk();
        $verify->assertJsonPath('user.phone', '+6281234567890');
        $this->assertDatabaseHas('users', [
            'email' => 'contract-phone-normalize@example.com',
            'phone' => '+6281234567890',
        ]);
    }

    public function test_register_verify_rejects_invalid_otp(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'contract-register-invalid-otp@example.com',
            'password' => 'secret123',
            'name' => 'Contract Invalid Otp',
        ]);

        $response->assertStatus(202);

        $verify = $this->postJson('/api/auth/register/verify', [
            'email' => 'contract-register-invalid-otp@example.com',
            'code' => '000000',
        ]);

        $verify->assertStatus(422);
        $verify->assertJsonPath('message', 'Invalid or expired verification code');

        $this->assertDatabaseMissing('users', [
            'email' => 'contract-register-invalid-otp@example.com',
        ]);
    }

    public function test_forgot_and_reset_password_flow_contract_for_frontend(): void
    {
        User::query()->create([
            'email' => 'contract-reset-password@example.com',
            'phone' => '+6281000000002',
            'password' => bcrypt('old-pass-123'),
            'name' => 'Contract Reset Password',
            'phoneVerifiedAt' => now(),
        ]);

        $forgot = $this->postJson('/api/auth/forgot-password', [
            'email' => 'contract-reset-password@example.com',
        ]);

        $forgot->assertOk();
        $forgot->assertJsonPath('success', true);
        $this->assertNotEmpty($forgot->json('reset.token'));

        $reset = $this->postJson('/api/auth/reset-password', [
            'email' => 'contract-reset-password@example.com',
            'token' => (string) $forgot->json('reset.token'),
            'password' => 'new-pass-123',
        ]);

        $reset->assertOk();
        $reset->assertJsonPath('success', true);
        $reset->assertJsonPath('message', 'Password reset successful');

        $login = $this->postJson('/api/auth/login', [
            'email' => 'contract-reset-password@example.com',
            'password' => 'new-pass-123',
        ]);

        $login->assertOk();
        $login->assertJsonStructure([
            'accessToken',
            'refreshToken',
            'user' => ['id', 'idUser', 'email'],
        ]);
    }

    public function test_forgot_password_returns_json_when_mail_transport_fails(): void
    {
        User::query()->create([
            'email' => 'contract-mail-fail@example.com',
            'password' => bcrypt('old-pass-123'),
            'name' => 'Contract Mail Fail',
        ]);

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new RuntimeException('SMTP unavailable'));

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'contract-mail-fail@example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('mailWarning', 'Reset token generated, but email could not be sent right now.');
        $this->assertNotEmpty($response->json('reset.token'));
    }

    public function test_refresh_accepts_snake_case_and_returns_snake_case_tokens(): void
    {
        $user = User::query()->create([
            'email' => 'contract-refresh@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Contract Refresh',
        ]);

        $refresh = AuthToken::issue($user, 'refresh', now()->addDays(1));

        $response = $this->postJson('/api/auth/refresh', [
            'refresh_token' => $refresh['plain'],
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'accessToken',
            'refreshToken',
            'access_token',
            'refresh_token',
            'expiresIn',
        ]);
    }

    public function test_profile_returns_top_level_name_and_email_for_settings_page(): void
    {
        $user = User::query()->create([
            'email' => 'contract-profile@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Contract Profile',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))->getJson('/api/auth/profile');

        $response->assertOk();
        $response->assertJsonPath('name', 'Contract Profile');
        $response->assertJsonPath('email', 'contract-profile@example.com');
        $response->assertJsonPath('idUser', $user->idUser);
    }

    public function test_update_profile_returns_name_email_and_id_user_fields(): void
    {
        $user = User::query()->create([
            'email' => 'contract-update@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Before Update',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))->putJson('/api/users/profile', [
            'name' => 'After Update',
            'email' => 'contract-update-new@example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('name', 'After Update');
        $response->assertJsonPath('email', 'contract-update-new@example.com');
        $response->assertJsonPath('idUser', $user->idUser);
    }

    public function test_transactions_index_returns_array_shape_used_by_frontend(): void
    {
        $user = User::query()->create([
            'email' => 'contract-transactions@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Contract Transactions',
        ]);

        $response = $this->withHeaders($this->authHeaders($user))->getJson('/api/transactions');

        $response->assertOk();
        $this->assertIsArray($response->json());
    }

    public function test_categories_index_returns_array_with_id_category_and_name(): void
    {
        $user = User::query()->create([
            'email' => 'contract-categories-index@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Contract Categories Index',
        ]);

        Category::query()->create([
            'name' => 'Makanan',
            'idUser' => $user->idUser,
        ]);

        $response = $this->withHeaders($this->authHeaders($user))->getJson('/api/categories');

        $response->assertOk();
        $response->assertJsonStructure([
            '*' => ['idCategory', 'name', 'idUser', 'createdAt'],
        ]);
    }

    public function test_category_create_and_delete_contract_for_frontend(): void
    {
        $user = User::query()->create([
            'email' => 'contract-categories-crud@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Contract Categories Crud',
        ]);

        $headers = $this->authHeaders($user);

        $create = $this->withHeaders($headers)->postJson('/api/categories', [
            'name' => 'Transportasi',
        ]);

        $create->assertCreated();
        $create->assertJsonStructure(['idCategory', 'name', 'idUser', 'createdAt']);

        $idCategory = (int) $create->json('idCategory');

        $delete = $this->withHeaders($headers)->deleteJson('/api/categories/' . $idCategory);
        $delete->assertOk();
        $delete->assertJsonPath('message', 'Category deleted');
    }

    public function test_budget_endpoints_contract_for_frontend_context(): void
    {
        $user = User::query()->create([
            'email' => 'contract-budgets@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Contract Budgets',
        ]);

        $category = Category::query()->create([
            'name' => 'Konsumsi',
            'idUser' => $user->idUser,
        ]);

        $headers = $this->authHeaders($user);

        $create = $this->withHeaders($headers)->postJson('/api/budgets', [
            'idCategory' => $category->idCategory,
            'period' => 'monthly',
            'periodStart' => '2026-02-01T00:00:00Z',
            'periodEnd' => '2026-02-28T23:59:59Z',
            'amount' => 500000,
        ]);

        $create->assertCreated();
        $create->assertJsonStructure([
            'idBudget', 'idUser', 'idCategory', 'period', 'periodStart', 'periodEnd', 'amount', 'createdAt', 'updatedAt',
        ]);

        $idBudget = (int) $create->json('idBudget');

        $index = $this->withHeaders($headers)->getJson('/api/budgets');
        $index->assertOk();
        $this->assertIsArray($index->json());

        $filter = $this->withHeaders($headers)->getJson('/api/budgets/filter?period=monthly&date=2026-02-10');
        $filter->assertOk();
        $this->assertIsArray($filter->json());

        Transaction::query()->create([
            'idUser' => $user->idUser,
            'idCategory' => $category->idCategory,
            'type' => 'expense',
            'amount' => 125000,
            'description' => 'Belanja mingguan',
            'date' => '2026-02-10 00:00:00',
        ]);

        $goals = $this->withHeaders($headers)->getJson('/api/budgets/goals?period=monthly&date=2026-02-10&type=expense');
        $goals->assertOk();
        $goals->assertJsonStructure([
            'period' => ['start', 'end', 'period'],
            'totals' => ['totalBudget', 'totalSpent', 'remaining', 'percent'],
            'data',
        ]);

        $usage = $this->withHeaders($headers)->getJson('/api/budgets/' . $idBudget . '/usage');
        $usage->assertOk();
        $usage->assertJsonStructure(['used', 'total', 'percent']);

        $update = $this->withHeaders($headers)->putJson('/api/budgets/' . $idBudget, [
            'amount' => 550000,
        ]);
        $update->assertOk();
        $update->assertJsonPath('amount', '550000.00');

        $delete = $this->withHeaders($headers)->deleteJson('/api/budgets/' . $idBudget);
        $delete->assertOk();
        $delete->assertJsonPath('message', 'Budget deleted');
    }

    public function test_category_delete_foreign_owner_returns_message_for_frontend(): void
    {
        $owner = User::query()->create([
            'email' => 'contract-category-owner@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Owner',
        ]);

        $other = User::query()->create([
            'email' => 'contract-category-other@example.com',
            'password' => bcrypt('secret123'),
            'name' => 'Other',
        ]);

        $category = Category::query()->create([
            'name' => 'Private Category',
            'idUser' => $owner->idUser,
        ]);

        $response = $this->withHeaders($this->authHeaders($other))->deleteJson('/api/categories/' . $category->idCategory);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Not allowed');
    }

    private function authHeaders(User $user): array
    {
        $token = AuthToken::issue($user, 'access', now()->addMinutes(30));

        return [
            'Authorization' => 'Bearer ' . $token['plain'],
            'Accept' => 'application/json',
        ];
    }
}
