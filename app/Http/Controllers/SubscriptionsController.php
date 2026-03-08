<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionsController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $lookbackDays = max(30, min(365, (int) $request->query('lookbackDays', 120)));
        $start = CarbonImmutable::now('UTC')->subDays($lookbackDays);

        $expenses = Transaction::query()
            ->where('idUser', $user->idUser)
            ->where('type', 'expense')
            ->whereNotNull('date')
            ->where('date', '>=', $start)
            ->orderBy('date')
            ->get();

        $groups = [];
        foreach ($expenses as $tx) {
            $description = $this->normalize((string) ($tx->description ?: ''));
            $source = strtolower(trim((string) ($tx->source ?: 'unknown')));
            $amount = number_format((float) $tx->amount, 2, '.', '');

            // If description is empty, use source+amount grouping fallback.
            $key = ($description !== '' ? $description : 'no-desc') . '|' . $source . '|' . $amount;

            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $tx;
        }

        $subscriptions = [];
        foreach ($groups as $key => $items) {
            if (count($items) < 2) {
                continue;
            }

            $intervalDays = $this->averageIntervalDays($items);
            if ($intervalDays < 20 || $intervalDays > 45) {
                // Filter to mostly monthly-like recurring charges.
                continue;
            }

            $last = $items[count($items) - 1];
            $nextDue = CarbonImmutable::parse((string) $last->date)->addDays((int) round($intervalDays));

            $subscriptions[] = [
                'label' => $this->labelForSubscription($last),
                'amount' => (float) $last->amount,
                'source' => $last->source,
                'occurrences' => count($items),
                'avgIntervalDays' => round($intervalDays, 1),
                'lastChargedAt' => $last->date,
                'nextDueAt' => $nextDue,
                'daysUntilDue' => CarbonImmutable::now('UTC')->diffInDays($nextDue, false),
            ];
        }

        usort($subscriptions, static function (array $a, array $b): int {
            return strcmp((string) $a['nextDueAt'], (string) $b['nextDueAt']);
        });

        $monthlyEstimate = 0.0;
        foreach ($subscriptions as $subscription) {
            $monthlyEstimate += (float) ($subscription['amount'] ?? 0);
        }

        return response()->json([
            'summary' => [
                'subscriptionCount' => count($subscriptions),
                'estimatedMonthlyTotal' => round($monthlyEstimate, 2),
                'dueSoonCount' => count(array_filter($subscriptions, static fn ($s) => (int) $s['daysUntilDue'] <= 7)),
            ],
            'items' => $subscriptions,
        ]);
    }

    private function averageIntervalDays(array $transactions): float
    {
        if (count($transactions) < 2) {
            return 0;
        }

        $diffs = [];
        for ($i = 1; $i < count($transactions); $i++) {
            $prev = CarbonImmutable::parse((string) $transactions[$i - 1]->date);
            $curr = CarbonImmutable::parse((string) $transactions[$i]->date);
            $diffs[] = max(1, $prev->diffInDays($curr));
        }

        return array_sum($diffs) / count($diffs);
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return $value;
    }

    private function labelForSubscription(Transaction $tx): string
    {
        $description = trim((string) ($tx->description ?: ''));
        if ($description !== '') {
            return $description;
        }

        if ($tx->source) {
            return 'Subscription via ' . $tx->source;
        }

        return 'Recurring expense';
    }
}
