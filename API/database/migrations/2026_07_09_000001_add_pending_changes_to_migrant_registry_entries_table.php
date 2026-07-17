<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migrant_registry_entries', function (Blueprint $table) {
            $table->string('pending_action', 50)->nullable()->after('current_assignee_role')->index();
            $table->json('pending_payload_json')->nullable()->after('payload_json');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('migrant_registry_entries', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'pending_action',
                'pending_payload_json',
            ]);
        });
    }
};
