<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('funding_sources')) {
            return;
        }

        Schema::create('funding_sources', function (Blueprint $table): void {
            $table->bigIncrements('idFundingSource');
            $table->unsignedBigInteger('idUser');
            $table->string('name', 80);
            $table->decimal('initialBalance', 18, 2)->default(0);
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['idUser', 'name'], 'uq_funding_source_user_name');
            $table->foreign('idUser')->references('idUser')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_sources');
    }
};