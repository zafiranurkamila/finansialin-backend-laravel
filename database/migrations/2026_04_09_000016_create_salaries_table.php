<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('salaries', function (Blueprint $table) {
            $table->bigIncrements('idSalary');
            $table->unsignedBigInteger('idUser');
            $table->decimal('amount', 18, 2);
            $table->date('salaryDate'); // Tanggal gajian diterima
            $table->date('nextSalaryDate')->nullable(); // Tanggal gajian berikutnya
            $table->enum('status', ['pending', 'received', 'cancelled'])->default('pending'); // Status gajian
            $table->text('description')->nullable(); // Keterangan (gajian bulanan, bonus, dll)
            $table->string('source')->nullable(); // Sumber gajian (nama perusahaan/instansi)
            $table->boolean('autoCreateTransaction')->default(true); // Apakah otomatis membuat transaksi income
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->index(['idUser', 'salaryDate'], 'idx_salary_user_date');
            $table->index('status', 'idx_salary_status');
            $table->foreign('idUser')->references('idUser')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salaries');
    }
};
