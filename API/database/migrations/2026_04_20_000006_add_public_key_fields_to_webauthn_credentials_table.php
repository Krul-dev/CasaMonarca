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
        Schema::table('webauthn_credentials', function (Blueprint $table): void {
            $table->longText('public_key')
                ->after('credential_id');
            $table->integer('public_key_algorithm')
                ->after('public_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('webauthn_credentials', function (Blueprint $table): void {
            $table->dropColumn([
                'public_key',
                'public_key_algorithm',
            ]);
        });
    }
};
