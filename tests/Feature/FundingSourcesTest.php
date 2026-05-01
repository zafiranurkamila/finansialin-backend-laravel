<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Category;
use App\Models\Resource;
use App\Models\User;
use App\Services\ResourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundingSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_resources_as_wallets(): void
    {
        $user = User::factory()->create();
        $headers = $this->authHeaders($user);

        ResourceService::initializeDefaultResources($user);

        $index = $this->withHeaders($headers)->getJson('/api/resources');
        $index->assertOk();
        $index->assertJsonCount(3, 'data');
        $index->assertJsonPath('data.0.source', 'mbanking');
        $index->assertJsonPath('data.1.source', 'emoney');
        $index->assertJsonPath('data.2.source', 'cash');
    }

    public function test_income_and_expense_update_selected_resource_balance(): void
    {
        $user = User::factory()->create();
        $headers = $this->authHeaders($user);

        $category = Category::query()->create([
            'name' => 'Food',
            'type' => 'expense',
            'idUser' => $user->idUser,
        ]);

        $mbanking = Resource::query()->create([
            'idUser' => $user->idUser,
            'source' => 'mbanking',
            'balance' => 0,
        ]);

        $cash = Resource::query()->create([
            'idUser' => $user->idUser,
            'source' => 'cash',
            'balance' => 100000,
        ]);

        $this->withHeaders($headers)->postJson('/api/transactions', [
            'type' => 'income',
            'amount' => 1000000,
            'idResource' => $mbanking->idResource,
        ])->assertCreated();

        $this->assertSame(1000000.0, (float) Resource::query()->findOrFail($mbanking->idResource)->balance);

        $expense = $this->withHeaders($headers)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 200000,
            'idCategory' => $category->idCategory,
            'idResource' => $cash->idResource,
        ]);

        $expense->assertStatus(422);
        $expense->assertJsonPath('message', 'Insufficient balance for selected resource');
        $expense->assertJsonPath('source', 'cash');

        $this->withHeaders($headers)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 250000,
            'idCategory' => $category->idCategory,
            'idResource' => $mbanking->idResource,
        ])->assertCreated();

        $this->assertSame(750000.0, (float) Resource::query()->findOrFail($mbanking->idResource)->balance);
    }

    public function test_income_cannot_use_cash_resource(): void
    {
        $user = User::factory()->create();
        $headers = $this->authHeaders($user);

        $cash = Resource::query()->create([
            'idUser' => $user->idUser,
            'source' => 'cash',
            'balance' => 0,
        ]);

        $response = $this->withHeaders($headers)->postJson('/api/transactions', [
            'type' => 'income',
            'amount' => 50000,
            'idResource' => $cash->idResource,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Income transactions can only be added to mbanking or emoney resources');
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
