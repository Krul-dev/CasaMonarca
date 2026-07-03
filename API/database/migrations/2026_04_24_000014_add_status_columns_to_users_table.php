<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('status')->default('active')->after('role')->index();
            $table->timestamp('suspended_at')->nullable()->after('status');
            $table->foreignId('suspended_by_user_id')
                ->nullable()
                ->after('suspended_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('suspension_reason')->nullable()->after('suspended_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['suspended_by_user_id']);
            $table->dropColumn([
                'status',
                'suspended_at',
                'suspended_by_user_id',
                'suspension_reason',
            ]);
        });
    }
};
