<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migrant_arco_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registry_entry_id')->constrained('migrant_registry_entries')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->string('requested_by_role', 50);
            $table->string('request_type', 50);
            $table->text('reason');
            $table->string('status', 50)->index();
            $table->boolean('escalated_to_admin')->default(false);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolved_by_role', 50)->nullable();
            $table->text('resolution_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migrant_arco_requests');
    }
};
