<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'twoFactorEnabled')) {
                $table->boolean('twoFactorEnabled')->default(false)->after('phoneVerifiedAt');
            }

            if (!Schema::hasColumn('users', 'twoFactorConfirmedAt')) {
                $table->timestamp('twoFactorConfirmedAt')->nullable()->after('twoFactorEnabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'twoFactorConfirmedAt')) {
                $table->dropColumn('twoFactorConfirmedAt');
            }

            if (Schema::hasColumn('users', 'twoFactorEnabled')) {
                $table->dropColumn('twoFactorEnabled');
            }
        });
    }
};
