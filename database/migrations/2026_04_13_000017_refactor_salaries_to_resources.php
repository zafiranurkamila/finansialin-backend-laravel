<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add salary_date to users table
        Schema::table('users', function (Blueprint $table) {
            $table->date('salary_date')->nullable()->after('email');
        });

        // Drop old salaries table
        Schema::dropIfExists('salaries');

        // Create new resources table
        Schema::create('resources', function (Blueprint $table) {
            $table->bigIncrements('idResource');
            $table->unsignedBigInteger('idUser');
            $table->enum('resourceType', ['mbanking', 'emoney', 'cash']); // 3 fixed resource types
            $table->decimal('balance', 18, 2)->default(0); // Current balance in this resource
            $table->string('source'); // Auto-generated: resourceType_userName
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent()->useCurrentOnUpdate();

            $table->index('idUser', 'idx_resource_user');
            $table->index(['idUser', 'resourceType'], 'idx_resource_user_type');
            $table->foreign('idUser')->references('idUser')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resources');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('salary_date');
        });
    }
};
