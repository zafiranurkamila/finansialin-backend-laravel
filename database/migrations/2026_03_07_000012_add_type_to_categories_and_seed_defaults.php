<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('type', 20)->default('expense')->after('name');
            $table->index(['idUser', 'type'], 'idx_category_user_type');
        });

        DB::table('categories')->whereNull('type')->update(['type' => 'expense']);

        $defaults = [
            ['name' => 'Salary', 'type' => 'income'],
            ['name' => 'Bonus', 'type' => 'income'],
            ['name' => 'Gift', 'type' => 'income'],
            ['name' => 'Food & Drinks', 'type' => 'expense'],
            ['name' => 'Transportation', 'type' => 'expense'],
            ['name' => 'Shopping', 'type' => 'expense'],
            ['name' => 'Bills & Utilities', 'type' => 'expense'],
            ['name' => 'Health', 'type' => 'expense'],
            ['name' => 'Entertainment', 'type' => 'expense'],
        ];

        foreach ($defaults as $default) {
            $exists = DB::table('categories')
                ->whereNull('idUser')
                ->where('name', $default['name'])
                ->where('type', $default['type'])
                ->exists();

            if (!$exists) {
                DB::table('categories')->insert([
                    'name' => $default['name'],
                    'type' => $default['type'],
                    'idUser' => null,
                    'createdAt' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('idx_category_user_type');
            $table->dropColumn('type');
        });
    }
};
