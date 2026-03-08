<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsightsController extends Controller
{
    public function assistant(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->attributes->get('auth_user');

        $prompt = (string) $request->query('prompt', 'summary');
        $now = CarbonImmutable::now('UTC');
        $from = $now->subDays(30);

        $recentTx = Transaction::query()
            ->where('idUser', $user->idUser)
            ->where('date', '>=', $from)
            ->orderByDesc('date')
            ->get();

        $income = (float) $recentTx->where('type', 'income')->sum('amount');
        $expense = (float) $recentTx->where('type', 'expense')->sum('amount');
        $net = $income - $expense;

        $categories = Category::query()->pluck('name', 'idCategory');

        $expenseByCategory = [];
        foreach ($recentTx->where('type', 'expense') as $tx) {
            $catName = $categories[$tx->idCategory] ?? 'Uncategorized';
            $expenseByCategory[$catName] = ($expenseByCategory[$catName] ?? 0) + (float) $tx->amount;
        }

        arsort($expenseByCategory);
        $topCategory = array_key_first($expenseByCategory);
        $topCategoryAmount = $topCategory ? (float) $expenseByCategory[$topCategory] : 0.0;

        $avgDailyExpense = $expense / 30;
        $projectedMonthlyExpense = $avgDailyExpense * 30;

        $monthStart = $now->startOfMonth();
        $monthEnd = $monthStart->addMonth();
        $activeBudgets = Budget::query()
            ->where('idUser', $user->idUser)
            ->where('periodStart', '<', $monthEnd)
            ->where('periodEnd', '>=', $monthStart)
            ->get();

        $warningCount = 0;
        foreach ($activeBudgets as $budget) {
            $spent = (float) Transaction::query()
                ->where('idUser', $user->idUser)
                ->where('type', 'expense')
                ->when($budget->idCategory, fn ($q) => $q->where('idCategory', $budget->idCategory))
                ->whereBetween('date', [$budget->periodStart, $budget->periodEnd])
                ->sum('amount');

            $limit = (float) $budget->amount;
            if ($limit > 0 && ($spent / $limit) >= 0.8) {
                $warningCount++;
            }
        }

        $savingsRate = $income > 0 ? ($net / $income) * 100 : 0;

        $summary = [
            'periodDays' => 30,
            'income' => round($income, 2),
            'expense' => round($expense, 2),
            'net' => round($net, 2),
            'savingsRate' => round($savingsRate, 2),
            'topExpenseCategory' => $topCategory,
            'topExpenseAmount' => round($topCategoryAmount, 2),
            'projectedMonthlyExpense' => round($projectedMonthlyExpense, 2),
            'activeBudgetWarnings' => $warningCount,
        ];

        $assistantReply = $this->buildAssistantReply($prompt, $summary);

        return response()->json([
            'summary' => $summary,
            'assistantReply' => $assistantReply,
            'quickPrompts' => [
                ['key' => 'summary', 'label' => 'Ringkas kondisi keuangan saya'],
                ['key' => 'saving_tips', 'label' => 'Kasih 3 strategi hemat minggu ini'],
                ['key' => 'what_to_cut', 'label' => 'Pengeluaran mana yang bisa dipangkas dulu'],
                ['key' => 'budget_alerts', 'label' => 'Budget mana yang paling rawan jebol'],
            ],
        ]);
    }

    private function buildAssistantReply(string $prompt, array $summary): string
    {
        $income = number_format((float) ($summary['income'] ?? 0), 0, ',', '.');
        $expense = number_format((float) ($summary['expense'] ?? 0), 0, ',', '.');
        $net = number_format((float) ($summary['net'] ?? 0), 0, ',', '.');
        $topCategory = (string) ($summary['topExpenseCategory'] ?? 'Tidak ada');
        $topAmount = number_format((float) ($summary['topExpenseAmount'] ?? 0), 0, ',', '.');
        $warningCount = (int) ($summary['activeBudgetWarnings'] ?? 0);

        return match ($prompt) {
            'saving_tips' =>
                "1) Pakai limit harian untuk kategori {$topCategory}.\n"
                . "2) Tunda 24 jam untuk pembelian non-prioritas.\n"
                . "3) Amankan minimal 10% dari pemasukan ke tabungan otomatis.",
            'what_to_cut' =>
                "Kategori paling besar 30 hari terakhir: {$topCategory} (Rp{$topAmount}). Fokus pangkas 10-15% di kategori ini dulu agar dampaknya cepat terasa.",
            'budget_alerts' =>
                $warningCount > 0
                    ? "Ada {$warningCount} budget yang sudah di atas 80%. Prioritaskan review kategori tersebut hari ini."
                    : "Belum ada budget yang melewati 80%. Kondisi budget masih aman.",
            default =>
                "30 hari terakhir: pemasukan Rp{$income}, pengeluaran Rp{$expense}, saldo bersih Rp{$net}. "
                . "Pengeluaran terbesar ada di {$topCategory}.",
        };
    }
}
