<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('auth_tokens', function (Blueprint $table) {
            $table->bigIncrements('idToken');
            $table->unsignedBigInteger('idUser');
            $table->string('tokenHash', 128)->unique();
            $table->string('type', 16); // access|refresh
            $table->timestamp('expiresAt');
            $table->timestamp('revokedAt')->nullable();
            $table->timestamp('createdAt')->useCurrent();

            $table->index(['idUser', 'type']);
            $table->index(['type', 'expiresAt']);
            $table->foreign('idUser')->references('idUser')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_tokens');
    }
};
