<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migrant_registry_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('registry_entry_id')->constrained('migrant_registry_entries')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('original_file_name');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->string('storage_disk')->nullable();
            $table->string('storage_path')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploaded_by_role', 50);
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('registry_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migrant_registry_documents');
    }
};
