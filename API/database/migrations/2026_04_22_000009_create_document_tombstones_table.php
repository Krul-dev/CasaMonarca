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
        Schema::create('document_tombstones', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('original_document_id');
            $table->string('title');
            $table->foreignId('deleted_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('deleted_at');
            $table->string('last_sha256', 64)->nullable();
            $table->unsignedInteger('revision_count')->default(0);
            $table->json('metadata')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_tombstones');
    }
};
