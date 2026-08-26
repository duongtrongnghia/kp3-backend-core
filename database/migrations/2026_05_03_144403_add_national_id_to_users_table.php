<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            // CCCD/CMND/Passport — stored encrypted (Laravel `encrypted` cast on model).
            // text size: encrypted payload is ~3-4× plaintext + IV. Use TEXT to be safe.
            // Unique constraint at DB level — one staff = one ID.
            // Note: encryption + unique together means uniqueness is checked on the
            // ciphertext, which is non-deterministic. We add a separate hashed lookup
            // column for uniqueness OR enforce in app layer. KISS for now: app-layer
            // uniqueness check via FormRequest unique:users,national_id_hash.
            $t->string('national_id_hash', 64)->nullable()->unique()->after('phone');
            $t->text('national_id_encrypted')->nullable()->after('national_id_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['national_id_hash']);
            $t->dropColumn(['national_id_hash', 'national_id_encrypted']);
        });
    }
};
