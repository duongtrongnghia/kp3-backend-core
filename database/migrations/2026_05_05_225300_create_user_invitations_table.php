<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_invitations', function (Blueprint $t) {
            $t->id();
            $t->string('email')->index();
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->string('role', 100);
            // Hashed token stored in DB — raw token sent in email URL only (never persisted).
            // bcrypt hash prevents DB dump leaking valid tokens.
            $t->string('token', 64)->unique();
            $t->timestamp('expires_at')->index();
            $t->foreignId('sent_by')->constrained('users')->cascadeOnDelete();
            $t->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('accepted_at')->nullable();
            $t->enum('status', ['pending', 'accepted', 'expired', 'revoked'])->default('pending')->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
