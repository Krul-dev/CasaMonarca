<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('document_revisions', 'signature_order_enforced')) {
            Schema::table('document_revisions', function (Blueprint $table): void {
                $table->boolean('signature_order_enforced')->default(false)->after('signature_status');
            });
        }

        if (! Schema::hasColumn('document_revisions', 'signature_policy_version')) {
            Schema::table('document_revisions', function (Blueprint $table): void {
                $table->unsignedBigInteger('signature_policy_version')->default(1)->after('signature_order_enforced');
            });
        }

        if (! Schema::hasColumn('document_signature_requirements', 'document_revision_id')) {
            Schema::table('document_signature_requirements', function (Blueprint $table): void {
                $table->foreignId('document_revision_id')->nullable()->after('id');
            });
        }

        if (Schema::hasColumn('document_signature_requirements', 'document_id')) {
            DB::table('document_signature_requirements')
                ->join('documents', 'documents.id', '=', 'document_signature_requirements.document_id')
                ->whereNotNull('documents.current_revision_id')
                ->update([
                    'document_signature_requirements.document_revision_id' => DB::raw('documents.current_revision_id'),
                ]);
        }

        if (Schema::hasColumn('documents', 'signature_order_enforced')) {
            DB::table('document_revisions')
                ->join('documents', 'documents.current_revision_id', '=', 'document_revisions.id')
                ->update([
                    'document_revisions.signature_order_enforced' => DB::raw('documents.signature_order_enforced'),
                ]);
        }

        DB::table('document_signature_requirements')
            ->join('document_signatures', 'document_signatures.id', '=', 'document_signature_requirements.fulfilled_by_signature_id')
            ->whereColumn('document_signatures.document_revision_id', '!=', 'document_signature_requirements.document_revision_id')
            ->update([
                'document_signature_requirements.fulfilled_by_signature_id' => null,
                'document_signature_requirements.fulfilled_at' => null,
            ]);

        DB::table('document_signature_requirements')
            ->whereNull('document_revision_id')
            ->delete();

        Schema::table('document_signature_requirements', function (Blueprint $table): void {
            $table->unsignedBigInteger('document_revision_id')->nullable(false)->change();
        });

        if ($this->foreignKeyForColumn('document_signature_requirements', 'document_revision_id') === null) {
            Schema::table('document_signature_requirements', function (Blueprint $table): void {
                $table->foreign('document_revision_id', 'doc_sig_req_revision_fk')
                    ->references('id')
                    ->on('document_revisions')
                    ->cascadeOnDelete();
            });
        }

        if ($this->indexForColumns('document_signature_requirements', ['document_revision_id', 'sequence']) === null) {
            Schema::table('document_signature_requirements', function (Blueprint $table): void {
                $table->index(['document_revision_id', 'sequence'], 'doc_sig_req_revision_sequence_idx');
            });
        }

        if (Schema::hasColumn('document_signature_requirements', 'document_id')) {
            $documentForeignKey = $this->foreignKeyForColumn(
                'document_signature_requirements',
                'document_id',
            );
            $documentSequenceIndex = $this->indexForColumns(
                'document_signature_requirements',
                ['document_id', 'sequence'],
            );

            Schema::table('document_signature_requirements', function (Blueprint $table) use ($documentForeignKey, $documentSequenceIndex): void {
                if ($documentForeignKey !== null) {
                    $table->dropForeign($documentForeignKey);
                }

                if ($documentSequenceIndex !== null) {
                    $table->dropIndex($documentSequenceIndex);
                }

                $table->dropColumn('document_id');
            });
        }

        if (Schema::hasColumn('documents', 'signature_order_enforced')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->dropColumn('signature_order_enforced');
            });
        }
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->boolean('signature_order_enforced')->default(false)->after('approval_note');
        });

        Schema::table('document_signature_requirements', function (Blueprint $table): void {
            $table->foreignId('document_id')->nullable()->after('id');
        });

        DB::table('document_signature_requirements')
            ->join('document_revisions', 'document_revisions.id', '=', 'document_signature_requirements.document_revision_id')
            ->update([
                'document_signature_requirements.document_id' => DB::raw('document_revisions.document_id'),
            ]);

        Schema::table('document_signature_requirements', function (Blueprint $table): void {
            $table->unsignedBigInteger('document_id')->nullable(false)->change();
            $table->foreign('document_id', 'doc_sig_req_document_fk')
                ->references('id')
                ->on('documents')
                ->cascadeOnDelete();
            $table->index(['document_id', 'sequence'], 'doc_sig_req_doc_sequence_idx');
            $table->dropForeign('doc_sig_req_revision_fk');
            $table->dropIndex('doc_sig_req_revision_sequence_idx');
            $table->dropColumn('document_revision_id');
        });

        Schema::table('document_revisions', function (Blueprint $table): void {
            $table->dropColumn(['signature_order_enforced', 'signature_policy_version']);
        });
    }

    private function foreignKeyForColumn(string $table, string $column): ?string
    {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if ($foreignKey['columns'] === [$column]) {
                return $foreignKey['name'];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     */
    private function indexForColumns(string $table, array $columns): ?string
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['columns'] === $columns) {
                return $index['name'];
            }
        }

        return null;
    }
};
