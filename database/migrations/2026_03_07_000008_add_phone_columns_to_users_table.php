<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 25)->nullable()->unique()->after('email');
            $table->timestamp('phoneVerifiedAt')->nullable()->after('emailVerifiedAt');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phoneVerifiedAt');
            $table->dropUnique('users_phone_unique');
            $table->dropColumn('phone');
        });
    }
};
