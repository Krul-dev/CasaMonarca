<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->timestamp('occurred_at')->index();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('actor_role')->nullable()->index();
            $table->string('event_type')->index();
            $table->string('resource_type')->nullable()->index();
            $table->unsignedBigInteger('resource_id')->nullable()->index();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->unsignedBigInteger('revision_id')->nullable()->index();
            $table->string('outcome')->index();
            $table->string('request_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->text('user_agent')->nullable();
            $table->string('session_id_hash', 64)->nullable()->index();
            $table->json('metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
