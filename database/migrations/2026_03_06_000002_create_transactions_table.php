<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('idTransaction');
            $table->unsignedBigInteger('idUser');
            $table->unsignedBigInteger('idCategory')->nullable();
            $table->enum('type', ['income', 'expense']);
            $table->decimal('amount', 18, 2);
            $table->text('description')->nullable();
            $table->timestamp('date')->useCurrent();
            $table->string('source')->nullable();
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->index(['idUser', 'date'], 'idx_tx_user_date');
            $table->index('idCategory', 'idx_tx_category');
            $table->foreign('idUser')->references('idUser')->on('users')->cascadeOnDelete();
            $table->foreign('idCategory')->references('idCategory')->on('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
