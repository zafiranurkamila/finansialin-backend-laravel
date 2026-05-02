<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrisWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_qris_webhook_requires_secret(): void
    {
        putenv('N8N_QRIS_WEBHOOK_SECRET=test-secret');

        $response = $this->postJson('/api/integrations/qris/email', [
            'email' => 'missing@example.com',
            'amount' => '12000',
        ]);

        $response->assertStatus(401);
    }

    public function test_qris_webhook_creates_expense_transaction(): void
    {
        putenv('N8N_QRIS_WEBHOOK_SECRET=test-secret');

        $user = User::factory()->create([
            'email' => 'webhook-user@example.com',
        ]);

        $resource = Resource::query()->create([
            'idUser' => $user->idUser,
            'source' => 'cash',
            'balance' => 50000,
        ]);

        $response = $this->withHeaders([
            'X-Webhook-Secret' => 'test-secret',
        ])->postJson('/api/integrations/qris/email', [
            'email' => $user->email,
            'amount' => 'Rp 25.000,50',
            'merchant' => 'Kopi Kita',
            'categoryName' => 'Food & Drinks',
            'paidAt' => '2026-03-07T09:15:00Z',
            'source' => 'cash',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.type', 'expense');
        $response->assertJsonPath('data.idUser', $user->idUser);

        $this->assertDatabaseHas('transactions', [
            'idUser' => $user->idUser,
            'type' => 'expense',
            'idResource' => $resource->idResource,
        ]);

        $transaction = Transaction::query()->where('idUser', $user->idUser)->latest('idTransaction')->first();
        $this->assertNotNull($transaction);
        $this->assertSame('25000.50', number_format((float) $transaction->amount, 2, '.', ''));
        $this->assertNotNull($transaction->idCategory);
        $this->assertSame($resource->idResource, $transaction->idResource);
        $this->assertSame(24999.5, (float) Resource::query()->findOrFail($resource->idResource)->balance);
    }
}
