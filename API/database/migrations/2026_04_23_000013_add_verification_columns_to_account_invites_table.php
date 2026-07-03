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
        Schema::table('account_invites', function (Blueprint $table): void {
            $table->timestamp('verified_out_of_band_at')->nullable()->index()->after('expires_at');
            $table->foreignId('verified_out_of_band_by_user_id')
                ->nullable()
                ->after('verified_out_of_band_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('verification_method')->nullable()->after('verified_out_of_band_by_user_id');
            $table->text('verification_note')->nullable()->after('verification_method');
            $table->timestamp('issued_at')->nullable()->index()->after('verification_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_invites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('verified_out_of_band_by_user_id');
            $table->dropColumn('verified_out_of_band_at');
            $table->dropColumn('verification_method');
            $table->dropColumn('verification_note');
            $table->dropColumn('issued_at');
        });
    }
};

