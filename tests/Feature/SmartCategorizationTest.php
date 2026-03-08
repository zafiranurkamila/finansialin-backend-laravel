<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartCategorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggest_returns_category_with_confidence_from_history_and_keywords(): void
    {
        $user = User::factory()->create();

        $food = Category::query()->create([
            'name' => 'Food & Drinks',
            'type' => 'expense',
            'idUser' => null,
        ]);

        Category::query()->create([
            'name' => 'Transportation',
            'type' => 'expense',
            'idUser' => null,
        ]);

        Transaction::query()->create([
            'idUser' => $user->idUser,
            'idCategory' => $food->idCategory,
            'type' => 'expense',
            'amount' => 35000,
            'description' => 'Kopi susu',
            'source' => 'Cash',
            'date' => now()->subDays(3),
        ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->getJson('/api/categories/suggest?type=expense&description=Kopi%20pagi&source=Cash');

        $response->assertOk();
        $response->assertJsonPath('suggested.name', 'Food & Drinks');
        $this->assertGreaterThan(0.5, (float) $response->json('confidence'));
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
