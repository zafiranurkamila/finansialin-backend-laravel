<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->bigIncrements('idPreference');
            $table->unsignedBigInteger('idUser')->unique();
            $table->string('theme', 16)->default('light');
            $table->boolean('hideBalance')->default(false);
            $table->boolean('dailyReminder')->default(true);
            $table->boolean('budgetLimitAlert')->default(true);
            $table->boolean('weeklySummary')->default(true);
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('idUser')->references('idUser')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
