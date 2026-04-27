<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pending_registrations', function (Blueprint $table): void {
            $table->bigIncrements('idPending');
            $table->string('email')->unique();
            $table->string('passwordHash');
            $table->string('name', 100)->nullable();
            $table->string('phone', 25)->nullable();
            $table->date('salaryDate')->nullable();
            $table->string('codeHash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expiresAt');
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->index(['expiresAt'], 'idx_pending_reg_exp');
            $table->index(['phone'], 'idx_pending_reg_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');
    }
};
