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
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('status')->default('active');
            $table->string('confidentiality')->default('confidential');
            $table->foreignId('owner_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->unsignedBigInteger('current_revision_id')->nullable();
            $table->timestamps();
        });

        Schema::create('document_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')
                ->constrained('documents')
                ->cascadeOnDelete();
            $table->foreignId('parent_revision_id')
                ->nullable()
                ->constrained('document_revisions')
                ->nullOnDelete();
            $table->foreignId('created_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('storage_disk')->default('local');
            $table->string('storage_path');
            $table->string('original_file_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('signature_status')->default('unsigned');
            $table->json('diff_metadata')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'revision_number']);
        });

        Schema::create('document_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_revision_id')
                ->constrained('document_revisions')
                ->cascadeOnDelete();
            $table->foreignId('signed_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('signature_type')->default('passkey');
            $table->string('verification_status')->default('pending');
            $table->timestamp('signed_at');
            $table->string('signature_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->foreign('current_revision_id')
                ->references('id')
                ->on('document_revisions')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign(['current_revision_id']);
        });

        Schema::dropIfExists('document_signatures');
        Schema::dropIfExists('document_revisions');
        Schema::dropIfExists('documents');
    }
};
