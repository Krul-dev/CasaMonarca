<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'staff')
            ->update([
                'role' => UserRole::default()->value,
            ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')
                ->default(UserRole::default()->value)
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')
            ->whereIn('role', [
                UserRole::Coordinator->value,
                UserRole::NonCoordinator->value,
                UserRole::Volunteer->value,
            ])
            ->update([
                'role' => 'staff',
            ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')
                ->default('staff')
                ->change();
        });
    }
};
