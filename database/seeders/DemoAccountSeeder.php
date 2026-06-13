<?php

namespace Database\Seeders;

use App\Models\Resource;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoAccountSeeder extends Seeder
{
    public const EMAIL = 'demo@finansialin.test';
    public const PASSWORD = 'password';

    public function run(): void
    {
        $user = User::query()->updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Demo Finansialin',
                'password' => Hash::make(self::PASSWORD),
                'salary_date' => now()->startOfMonth()->toDateString(),
                'emailVerifiedAt' => now(),
                'twoFactorEnabled' => false,
                'twoFactorConfirmedAt' => null,
            ]
        );

        foreach (['mbanking', 'emoney', 'cash'] as $source) {
            Resource::query()->firstOrCreate(
                [
                    'idUser' => $user->idUser,
                    'source' => $source,
                ],
                [
                    'balance' => 0,
                ]
            );
        }

        UserPreference::query()->firstOrCreate(
            ['idUser' => $user->idUser],
            [
                'theme' => 'light',
                'hideBalance' => false,
                'dailyReminder' => true,
                'budgetLimitAlert' => true,
                'weeklySummary' => true,
            ]
        );

        $this->command?->info(sprintf(
            'Demo account is ready: %s / %s',
            self::EMAIL,
            self::PASSWORD
        ));
    }
}
