<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->bigIncrements('idCategory');
            $table->string('name');
            $table->unsignedBigInteger('idUser')->nullable();
            $table->timestamp('createdAt')->useCurrent();

            $table->index('idUser', 'idx_category_user');
            $table->foreign('idUser')->references('idUser')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
