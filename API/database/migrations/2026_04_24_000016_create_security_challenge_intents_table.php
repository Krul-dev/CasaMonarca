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
        Schema::create('security_challenge_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('purpose')->index();
            $table->string('status')->index();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('target_type')->nullable()->index();
            $table->unsignedBigInteger('target_id')->nullable()->index();
            $table->string('challenge_hash', 64)->index();
            $table->json('payload')->nullable();
            $table->string('origin')->nullable();
            $table->string('rp_id')->nullable()->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable()->index();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'actor_user_id', 'status']);
            $table->index(['target_type', 'target_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_challenge_intents');
    }
};
