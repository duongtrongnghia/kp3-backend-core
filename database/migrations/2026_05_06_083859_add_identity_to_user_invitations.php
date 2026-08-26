<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 polish — capture identity (CCCD/dob/gender/address) at invite time,
 * transfer to User on accept. Pre-fix: invitation only stored email/name/role,
 * super_admin couldn't pre-fill the employee's identity → invited users land
 * with empty PII fields.
 *
 * Mirror the User table identity columns (Phase 2.5):
 *   - national_id_encrypted: encrypted-cast (random IV ciphertext)
 *   - national_id_hash: HMAC-sha256(value, app.key) for indexed unique-lookup
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_invitations', function (Blueprint $table) {
            $table->string('national_id_encrypted')->nullable()->after('role');
            $table->string('national_id_hash', 64)->nullable()->index()->after('national_id_encrypted');
            $table->date('dob')->nullable()->after('national_id_hash');
            $table->string('gender', 10)->nullable()->after('dob');
            $table->string('country_code', 8)->nullable()->after('gender');
            $table->string('address_province', 64)->nullable()->after('country_code');
            $table->string('address_commune', 64)->nullable()->after('address_province');
            $table->string('address_street', 255)->nullable()->after('address_commune');
        });
    }

    public function down(): void
    {
        Schema::table('user_invitations', function (Blueprint $table) {
            $table->dropColumn([
                'national_id_encrypted',
                'national_id_hash',
                'dob',
                'gender',
                'country_code',
                'address_province',
                'address_commune',
                'address_street',
            ]);
        });
    }
};
