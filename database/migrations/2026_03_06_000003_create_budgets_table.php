<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('budgets', function (Blueprint $table) {
            $table->bigIncrements('idBudget');
            $table->unsignedBigInteger('idUser');
            $table->unsignedBigInteger('idCategory')->nullable();
            $table->string('period')->default('monthly');
            $table->timestamp('periodStart');
            $table->timestamp('periodEnd');
            $table->decimal('amount', 18, 2);
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->index(['idUser', 'periodStart', 'periodEnd'], 'idx_budget_user_period');
            $table->index('idCategory', 'idx_budget_category');
            $table->foreign('idUser')->references('idUser')->on('users')->cascadeOnDelete();
            $table->foreign('idCategory')->references('idCategory')->on('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
