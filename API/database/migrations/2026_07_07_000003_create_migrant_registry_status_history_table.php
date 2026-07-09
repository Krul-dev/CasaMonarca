<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migrant_registry_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registry_entry_id')->constrained('migrant_registry_entries')->cascadeOnDelete();
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->foreignId('changed_by')->constrained('users')->cascadeOnDelete();
            $table->string('changed_by_role', 50);
            $table->text('reason')->nullable();
            $table->foreignId('signature_id')->nullable()->constrained('migrant_registry_signatures')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migrant_registry_status_history');
    }
};
