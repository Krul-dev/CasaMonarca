<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migrant_registry_entries', function (Blueprint $table) {
            $table->foreignId('pending_requested_by')
                ->nullable()
                ->after('pending_action')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('pending_requested_by_role', 50)
                ->nullable()
                ->after('pending_requested_by');
        });
    }

    public function down(): void
    {
        Schema::table('migrant_registry_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_requested_by');
            $table->dropColumn('pending_requested_by_role');
        });
    }
};
