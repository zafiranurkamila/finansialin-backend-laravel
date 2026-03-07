<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->bigIncrements('idNotification');
            $table->unsignedBigInteger('idUser');
            $table->string('type');
            $table->boolean('read')->default(false);
            $table->text('message')->nullable();
            $table->timestamp('createdAt')->useCurrent();

            $table->index(['idUser', 'read'], 'idx_notification_user_read');
            $table->foreign('idUser')->references('idUser')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
