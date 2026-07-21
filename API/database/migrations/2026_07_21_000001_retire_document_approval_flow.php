<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_signature_requirements')->delete();

        DB::table('documents')
            ->where('status', 'pending_approval')
            ->update([
                'status' => 'active',
                'approved_at' => DB::raw('COALESCE(approved_at, created_at)'),
            ]);

        DB::table('documents')->update([
            'signature_order_enforced' => false,
        ]);
    }

    public function down(): void
    {
        // Activating queued documents is intentionally irreversible.
    }
};
