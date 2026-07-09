<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migrant_registry_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registry_entry_id')->constrained('migrant_registry_entries')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('actor_role', 50);
            $table->string('action_type', 100);
            $table->string('algorithm', 50);
            $table->longText('signature_payload');
            $table->string('public_key_ref')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migrant_registry_signatures');
    }
};
