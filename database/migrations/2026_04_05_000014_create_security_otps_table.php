<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('security_otps', function (Blueprint $table) {
            $table->bigIncrements('idOtp');
            $table->unsignedBigInteger('idUser');
            $table->string('purpose', 32);
            $table->string('codeHash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expiresAt');
            $table->timestamp('consumedAt')->nullable();
            $table->timestamp('createdAt')->useCurrent();

            $table->index(['idUser', 'purpose'], 'idx_otp_user_purpose');
            $table->index(['purpose', 'expiresAt'], 'idx_otp_purpose_exp');
            $table->foreign('idUser')->references('idUser')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_otps');
    }
};
