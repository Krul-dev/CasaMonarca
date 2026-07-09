<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migrant_registry_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('created_by_role', 50);
            $table->string('current_status', 50)->index();
            $table->string('current_assignee_role', 50)->nullable()->index();
            $table->json('payload_json');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migrant_registry_entries');
    }
};
