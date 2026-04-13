<?php

namespace App\Http\Controllers;

use App\Models\Salary;
use App\Models\Transaction;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class SalaryController extends Controller
{
    /**
     * Tampilkan daftar gajian user
     * GET /api/salaries
     */
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $query = Salary::where('idUser', $user->idUser);

        // Filter berdasarkan status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter berdasarkan bulan/tahun
        if ($request->has('month') && $request->has('year')) {
            $query->whereYear('salaryDate', $request->year)
                ->whereMonth('salaryDate', $request->month);
        }

        $salaries = $query->orderBy('salaryDate', 'desc')->paginate(15);

        return response()->json([
            'message' => 'Daftar gajian berhasil diambil',
            'data' => $salaries,
        ]);
    }

    /**
     * Buat record gajian baru
     * POST /api/salaries
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'salaryDate' => 'required|date',
            'nextSalaryDate' => 'nullable|date',
            'description' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:255',
            'autoCreateTransaction' => 'boolean',
        ]);

        $user = auth()->user();

        // Jika nextSalaryDate tidak diberikan, hitung otomatis (1 bulan setelahnya)
        if (!$validated['nextSalaryDate']) {
            $validated['nextSalaryDate'] = \Carbon\Carbon::parse($validated['salaryDate'])
                ->addMonth()
                ->toDateString();
        }

        $salary = Salary::create([
            'idUser' => $user->idUser,
            ...$validated,
        ]);

        return response()->json([
            'message' => 'Gajian berhasil ditambahkan',
            'data' => $salary,
        ], 201);
    }

    /**
     * Tampilkan detail gajian
     * GET /api/salaries/{id}
     */
    public function show($id): JsonResponse
    {
        $user = auth()->user();
        $salary = Salary::where('idUser', $user->idUser)->findOrFail($id);

        return response()->json([
            'message' => 'Detail gajian berhasil diambil',
            'data' => $salary,
        ]);
    }

    /**
     * Update gajian
     * PUT /api/salaries/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        $user = auth()->user();
        $salary = Salary::where('idUser', $user->idUser)->findOrFail($id);

        $validated = $request->validate([
            'amount' => 'numeric|min:0',
            'salaryDate' => 'date',
            'nextSalaryDate' => 'nullable|date',
            'description' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:255',
            'status' => 'in:pending,received,cancelled',
            'autoCreateTransaction' => 'boolean',
        ]);

        $salary->update($validated);

        return response()->json([
            'message' => 'Gajian berhasil diperbarui',
            'data' => $salary,
        ]);
    }

    /**
     * Tandai gajian sebagai diterima
     * POST /api/salaries/{id}/receive
     */
    public function receive($id): JsonResponse
    {
        $user = auth()->user();
        $salary = Salary::where('idUser', $user->idUser)->findOrFail($id);

        if ($salary->status === 'received') {
            return response()->json([
                'message' => 'Gajian sudah ditandai sebagai diterima',
            ], 400);
        }

        if ($salary->status === 'cancelled') {
            return response()->json([
                'message' => 'Gajian yang dibatalkan tidak dapat ditandai sebagai diterima',
            ], 400);
        }

        $salary->update(['status' => 'received']);

        // Auto-create transaksi income jika autoCreateTransaction = true
        if ($salary->autoCreateTransaction) {
            // Get atau create category "Gajian" dengan type income
            $salaryCategory = Category::firstOrCreate(
                [
                    'idUser' => $user->idUser,
                    'name' => 'Gajian',
                    'type' => 'income',
                ],
                [
                    'description' => 'Kategori untuk transaksi gajian',
                    'color' => '#22c55e', // Green
                ]
            );

            Transaction::create([
                'idUser' => $user->idUser,
                'idCategory' => $salaryCategory->idCategory,
                'type' => 'income',
                'amount' => $salary->amount,
                'description' => $salary->description ?? 'Gajian ' . $salary->salaryDate->format('F Y'),
                'date' => $salary->salaryDate,
                'source' => $salary->source,
            ]);
        }

        return response()->json([
            'message' => 'Gajian berhasil ditandai sebagai diterima',
            'data' => $salary,
        ]);
    }

    /**
     * Batalkan gajian
     * POST /api/salaries/{id}/cancel
     */
    public function cancel($id): JsonResponse
    {
        $user = auth()->user();
        $salary = Salary::where('idUser', $user->idUser)->findOrFail($id);

        if ($salary->status === 'received') {
            return response()->json([
                'message' => 'Tidak dapat membatalkan gajian yang sudah diterima',
            ], 400);
        }

        $salary->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Gajian berhasil dibatalkan',
            'data' => $salary,
        ]);
    }

    /**
     * Ambil ringkasan gajian
     * GET /api/salaries/summary/overview
     */
    public function summary(): JsonResponse
    {
        $user = auth()->user();
        $currentMonth = now();

        // Total gajian bulan ini
        $totalThisMonth = Salary::where('idUser', $user->idUser)
            ->whereYear('salaryDate', $currentMonth->year)
            ->whereMonth('salaryDate', $currentMonth->month)
            ->sum('amount');

        // Gajian pending
        $pendingSalaries = Salary::where('idUser', $user->idUser)
            ->where('status', 'pending')
            ->get();

        // Gajian terakhir
        $lastSalary = Salary::where('idUser', $user->idUser)
            ->orderBy('salaryDate', 'desc')
            ->first();

        // Gajian berikutnya
        $nextSalary = Salary::where('idUser', $user->idUser)
            ->where('status', 'pending')
            ->orderBy('salaryDate', 'asc')
            ->first();

        // Total gajian tahun ini
        $totalThisYear = Salary::where('idUser', $user->idUser)
            ->whereYear('salaryDate', $currentMonth->year)
            ->where('status', 'received')
            ->sum('amount');

        return response()->json([
            'message' => 'Ringkasan gajian berhasil diambil',
            'data' => [
                'totalThisMonth' => $totalThisMonth,
                'pendingCount' => $pendingSalaries->count(),
                'pendingSalaries' => $pendingSalaries,
                'lastSalary' => $lastSalary,
                'nextSalary' => $nextSalary,
                'totalThisYear' => $totalThisYear,
            ],
        ]);
    }

    /**
     * Hapus gajian
     * DELETE /api/salaries/{id}
     */
    public function destroy($id): JsonResponse
    {
        $user = auth()->user();
        $salary = Salary::where('idUser', $user->idUser)->findOrFail($id);

        // Jangan hapus gajian yang sudah diterima
        if ($salary->status === 'received') {
            return response()->json([
                'message' => 'Tidak dapat menghapus gajian yang sudah diterima',
            ], 400);
        }

        $salary->delete();

        return response()->json([
            'message' => 'Gajian berhasil dihapus',
        ]);
    }
}
