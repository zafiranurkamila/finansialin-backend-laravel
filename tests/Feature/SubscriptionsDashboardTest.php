<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_detects_monthly_recurring_subscriptions(): void
    {
        $user = User::factory()->create();

        Transaction::query()->create([
            'idUser' => $user->idUser,
            'type' => 'expense',
            'amount' => 149000,
            'description' => 'Netflix',
            'source' => 'BCA',
            'date' => now()->subDays(90),
        ]);

        Transaction::query()->create([
            'idUser' => $user->idUser,
            'type' => 'expense',
            'amount' => 149000,
            'description' => 'Netflix',
            'source' => 'BCA',
            'date' => now()->subDays(60),
        ]);

        Transaction::query()->create([
            'idUser' => $user->idUser,
            'type' => 'expense',
            'amount' => 149000,
            'description' => 'Netflix',
            'source' => 'BCA',
            'date' => now()->subDays(30),
        ]);

        $response = $this->withHeaders($this->authHeaders($user))
            ->getJson('/api/subscriptions/dashboard?lookbackDays=120');

        $response->assertOk();
        $response->assertJsonPath('summary.subscriptionCount', 1);
        $response->assertJsonPath('items.0.label', 'Netflix');
        $this->assertGreaterThan(0, (float) $response->json('summary.estimatedMonthlyTotal'));
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
