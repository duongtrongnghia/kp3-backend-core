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
            // ISO country code (VN/US/...). Province/commune codes already live in
            // address_province + address_commune (semantics: store CODE, FE resolves
            // display name via /provinces/* lookup like inventory locations modal).
            $t->string('country_code', 8)->nullable()->after('address_province');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('country_code');
        });
    }
};
