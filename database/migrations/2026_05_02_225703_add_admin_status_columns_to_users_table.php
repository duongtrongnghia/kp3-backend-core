<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // status enum: active|inactive|locked. Default active for new + existing rows.
            $t->enum('status', ['active', 'inactive', 'locked'])
                ->default('active')
                ->after('role')
                ->index();

            $t->timestamp('last_login_at')->nullable()->index();

            // Lock metadata (set by user.lock action)
            $t->timestamp('locked_at')->nullable();
            $t->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('lock_reason')->nullable();

            // Deactivation metadata (set by user.deactivate action)
            $t->timestamp('deactivated_at')->nullable();
            $t->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('deactivation_reason')->nullable();

            // Soft delete (admin can permanently delete via separate path)
            $t->softDeletes();
        });

        // Backfill existing rows — already get default 'active' but explicit for safety.
        DB::table('users')->whereNull('status')->update(['status' => 'active']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropForeign(['locked_by']);
            $t->dropForeign(['deactivated_by']);
            $t->dropColumn([
                'status',
                'last_login_at',
                'locked_at',
                'locked_by',
                'lock_reason',
                'deactivated_at',
                'deactivated_by',
                'deactivation_reason',
                'deleted_at',
            ]);
        });
    }
};
