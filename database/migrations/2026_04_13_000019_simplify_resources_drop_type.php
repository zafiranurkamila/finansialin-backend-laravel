<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Drop foreign key from transactions first
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['idResource']);
            $table->dropIndex('idx_transaction_resource');
        });

        // Drop old resources table
        Schema::dropIfExists('resources');

        // Create new resources table without resourceType
        Schema::create('resources', function (Blueprint $table) {
            $table->bigIncrements('idResource');
            $table->unsignedBigInteger('idUser');
            $table->string('source'); // Wallet type: mbanking, emoney, cash
            $table->decimal('balance', 18, 2)->default(0); // Current balance in this resource
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->index('idUser', 'idx_resource_user');
            $table->index(['idUser', 'source'], 'idx_resource_user_source');
            $table->foreign('idUser')->references('idUser')->on('users')->cascadeOnDelete();
        });

        // Re-add foreign key to transactions
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('idResource', 'idx_transaction_resource');
            $table->foreign('idResource')->references('idResource')->on('resources')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['idResource']);
            $table->dropIndex('idx_transaction_resource');
        });

        Schema::dropIfExists('resources');
    }
};
