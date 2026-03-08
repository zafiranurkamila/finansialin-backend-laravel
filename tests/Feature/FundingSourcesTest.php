<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundingSourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_and_list_funding_sources(): void
    {
        $user = User::factory()->create();
        $headers = $this->authHeaders($user);

        $create = $this->withHeaders($headers)->postJson('/api/funding-sources', [
            'name' => 'Cash',
            'initialBalance' => 500000,
        ]);

        $create->assertCreated();
        $create->assertJsonPath('name', 'Cash');
        $this->assertSame(500000.0, (float) $create->json('initialBalance'));

        $index = $this->withHeaders($headers)->getJson('/api/funding-sources');
        $index->assertOk();
        $index->assertJsonCount(1);
        $index->assertJsonPath('0.name', 'Cash');
    }

    public function test_expense_with_source_cannot_exceed_selected_source_balance(): void
    {
        $user = User::factory()->create();
        $headers = $this->authHeaders($user);

        $category = Category::query()->create([
            'name' => 'Food',
            'type' => 'expense',
            'idUser' => $user->idUser,
        ]);

        $this->withHeaders($headers)->postJson('/api/funding-sources', [
            'name' => 'Cash',
            'initialBalance' => 100000,
        ])->assertCreated();

        $this->withHeaders($headers)->postJson('/api/funding-sources', [
            'name' => 'Bank',
            'initialBalance' => 0,
        ])->assertCreated();

        // Make global balance high via Bank, while Cash remains limited.
        $this->withHeaders($headers)->postJson('/api/transactions', [
            'type' => 'income',
            'amount' => 1000000,
            'source' => 'Bank',
        ])->assertCreated();

        $response = $this->withHeaders($headers)->postJson('/api/transactions', [
            'type' => 'expense',
            'amount' => 200000,
            'idCategory' => $category->idCategory,
            'source' => 'Cash',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Insufficient balance for selected funding source');
        $response->assertJsonPath('source', 'Cash');
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
