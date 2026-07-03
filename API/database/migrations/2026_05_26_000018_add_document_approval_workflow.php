<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('documents', 'approved_at')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->timestamp('approved_at')->nullable()->after('current_revision_id')->index();
            });
        }

        if (! Schema::hasColumn('documents', 'approved_by_user_id')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->foreignId('approved_by_user_id')
                    ->nullable()
                    ->after('approved_at');
                $table->foreign('approved_by_user_id', 'docs_approved_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('documents', 'approval_note')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->text('approval_note')->nullable()->after('approved_by_user_id');
            });
        }

        if (! Schema::hasColumn('documents', 'signature_order_enforced')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->boolean('signature_order_enforced')->default(false)->after('approval_note');
            });
        }

        DB::table('documents')
            ->where('status', 'active')
            ->whereNull('approved_at')
            ->update([
                'approved_at' => DB::raw('created_at'),
            ]);

        if (! Schema::hasTable('document_signature_requirements')) {
            Schema::create('document_signature_requirements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('document_id');
                $table->unsignedInteger('sequence');
                $table->string('signer_role')->nullable()->index();
                $table->foreignId('signer_user_id')->nullable();
                $table->foreignId('fulfilled_by_signature_id')->nullable();
                $table->timestamp('fulfilled_at')->nullable()->index();
                $table->timestamps();

                $table->foreign('document_id', 'doc_sig_req_document_fk')
                    ->references('id')
                    ->on('documents')
                    ->cascadeOnDelete();
                $table->foreign('signer_user_id', 'doc_sig_req_signer_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
                $table->foreign('fulfilled_by_signature_id', 'doc_sig_req_fulfilled_sig_fk')
                    ->references('id')
                    ->on('document_signatures')
                    ->nullOnDelete();
                $table->index(['document_id', 'sequence'], 'doc_sig_req_doc_sequence_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signature_requirements');

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign('docs_approved_by_fk');
            $table->dropColumn([
                'approved_at',
                'approved_by_user_id',
                'approval_note',
                'signature_order_enforced',
            ]);
        });
    }
};
