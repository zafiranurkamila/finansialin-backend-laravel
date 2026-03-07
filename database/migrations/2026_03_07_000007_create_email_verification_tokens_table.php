<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_verification_tokens', function (Blueprint $table) {
            $table->bigIncrements('idVerificationToken');
            $table->unsignedBigInteger('idUser');
            $table->string('tokenHash', 128)->unique();
            $table->timestamp('expiresAt');
            $table->timestamp('verifiedAt')->nullable();
            $table->timestamp('createdAt')->useCurrent();

            $table->index(['idUser', 'expiresAt']);
            $table->foreign('idUser')->references('idUser')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_verification_tokens');
    }
};
