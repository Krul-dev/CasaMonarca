<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migrant_arco_requests', function (Blueprint $table): void {
            $table->json('original_payload_json')->nullable()->after('reason');
            $table->json('proposed_payload_json')->nullable()->after('original_payload_json');
            $table->string('original_payload_hash', 64)->nullable()->after('proposed_payload_json');
            $table->string('proposed_payload_hash', 64)->nullable()->after('original_payload_hash');
            $table->timestamp('completed_at')->nullable()->after('resolution_reason');
        });

        Schema::create('migrant_arco_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('arco_request_id')->constrained('migrant_arco_requests')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 50);
            $table->string('action_type', 100);
            $table->string('algorithm', 50)->default('webauthn-passkey');
            $table->longText('signature_payload');
            $table->string('public_key_ref')->nullable();
            $table->timestamp('verified_at');
            $table->timestamps();
        });

        Schema::create('migrant_arco_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('arco_request_id')->constrained('migrant_arco_requests')->cascadeOnDelete();
            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_role', 50);
            $table->text('reason')->nullable();
            $table->foreignId('signature_id')->nullable()->constrained('migrant_arco_signatures')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('migrant_arco_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('arco_request_id')->unique()->constrained('migrant_arco_requests')->cascadeOnDelete();
            $table->string('storage_disk')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('filename');
            $table->string('mime_type', 100)->default('application/pdf');
            $table->unsignedBigInteger('byte_size');
            $table->string('sha256', 64);
            $table->timestamp('generated_at');
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });

        Schema::table('migrant_registry_status_history', function (Blueprint $table): void {
            $table->foreignId('arco_request_id')->nullable()->after('signature_id')
                ->constrained('migrant_arco_requests')->nullOnDelete();
        });

        DB::table('migrant_arco_requests')->whereIn('status', ['opened_by_operator', 'under_review_by_coordinator'])
            ->update(['status' => 'pending_coordinator']);
        DB::table('migrant_arco_requests')->where('status', 'needs_admin_deletion')
            ->update(['status' => 'pending_admin']);
        DB::table('migrant_arco_requests')->whereIn('status', ['approved', 'executed'])
            ->update(['status' => 'completed']);
    }

    public function down(): void
    {
        Schema::table('migrant_registry_status_history', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('arco_request_id');
        });
        Schema::dropIfExists('migrant_arco_artifacts');
        Schema::dropIfExists('migrant_arco_status_history');
        Schema::dropIfExists('migrant_arco_signatures');
        Schema::table('migrant_arco_requests', function (Blueprint $table): void {
            $table->dropColumn(['original_payload_json', 'proposed_payload_json', 'original_payload_hash', 'proposed_payload_hash', 'completed_at']);
        });
    }
};
