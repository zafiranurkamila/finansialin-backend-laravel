<?php

namespace Database\Seeders;

use App\Models\Salary;
use App\Models\User;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class SalarySeeder extends Seeder
{
    /**
     * Seed data gajian contoh untuk testing
     */
    public function run(): void
    {
        // Ambil user pertama (atau buat satu jika belum ada)
        $user = User::first();

        if (!$user) {
            $user = User::create([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => bcrypt('password'),
            ]);
        }

        // Hapus gajian lama untuk avoid duplikasi
        Salary::where('idUser', $user->idUser)->delete();

        // Data gajian contoh
        $salaries = [
            [
                'idUser' => $user->idUser,
                'amount' => 5000000,
                'salaryDate' => Carbon::now()->subMonths(2)->startOfMonth(),
                'nextSalaryDate' => Carbon::now()->subMonth()->startOfMonth(),
                'status' => 'received',
                'description' => 'Gajian Bulanan February 2026',
                'source' => 'PT Maju Jaya Indonesia',
                'autoCreateTransaction' => true,
            ],
            [
                'idUser' => $user->idUser,
                'amount' => 5000000,
                'salaryDate' => Carbon::now()->subMonth()->startOfMonth(),
                'nextSalaryDate' => Carbon::now()->startOfMonth(),
                'status' => 'received',
                'description' => 'Gajian Bulanan March 2026',
                'source' => 'PT Maju Jaya Indonesia',
                'autoCreateTransaction' => true,
            ],
            [
                'idUser' => $user->idUser,
                'amount' => 5000000,
                'salaryDate' => Carbon::now()->startOfMonth(),
                'nextSalaryDate' => Carbon::now()->addMonth()->startOfMonth(),
                'status' => 'pending',
                'description' => 'Gajian Bulanan April 2026',
                'source' => 'PT Maju Jaya Indonesia',
                'autoCreateTransaction' => true,
            ],
            [
                'idUser' => $user->idUser,
                'amount' => 2500000,
                'salaryDate' => Carbon::now()->addMonth()->toDateString(),
                'nextSalaryDate' => Carbon::now()->addMonths(2)->startOfMonth(),
                'status' => 'pending',
                'description' => 'Bonus Performa April 2026',
                'source' => 'PT Maju Jaya Indonesia',
                'autoCreateTransaction' => true,
            ],
        ];

        foreach ($salaries as $salary) {
            Salary::create($salary);
        }

        $this->command->info('Salary data seeded successfully!');
    }
}
