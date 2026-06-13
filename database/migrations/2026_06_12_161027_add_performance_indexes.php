<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['idUser', 'idCategory'], 'idx_tx_user_category');
            $table->index(['idUser', 'type', 'date'], 'idx_tx_user_type_date');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['idUser', 'createdAt'], 'idx_notif_user_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_tx_user_category');
            $table->dropIndex('idx_tx_user_type_date');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_notif_user_created');
        });
    }
};
