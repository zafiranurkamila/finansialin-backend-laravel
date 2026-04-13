<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('idResource')->nullable()->after('idCategory');
            $table->index('idResource', 'idx_transaction_resource');
            $table->foreign('idResource')->references('idResource')->on('resources')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['idResource']);
            $table->dropIndex('idx_transaction_resource');
            $table->dropColumn('idResource');
        });
    }
};
