<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 hardening (review H1) — add deterministic HMAC lookup hash for invitation tokens.
 *
 * Pre-fix: findByRawToken() bcrypt-compared against EVERY pending invitation row.
 * O(N) DoS amplification + defeats the unique index on `token`.
 *
 * Post-fix: token_lookup_hash = hash_hmac('sha256', $rawToken, app.key) — indexed,
 * 1 row lookup, then Hash::check() against existing bcrypt token for timing-safe verify.
 *
 * Existing pending invitations from prior Phase 3 commits will have null lookup_hash —
 * those tokens become unusable. Acceptable: feature shipped same day, no production
 * data; admins can resend via the resend flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_invitations', function (Blueprint $table) {
            $table->string('token_lookup_hash', 64)->nullable()->after('token');
            $table->unique('token_lookup_hash');
        });
    }

    public function down(): void
    {
        Schema::table('user_invitations', function (Blueprint $table) {
            $table->dropUnique(['token_lookup_hash']);
            $table->dropColumn('token_lookup_hash');
        });
    }
};
