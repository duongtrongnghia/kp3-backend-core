<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_settings', function (Blueprint $table) {
            $table->id();
            $table->string('module', 50);
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->timestamps();

            $table->unique(['module', 'key'], 'uk_module_key');
            $table->index('module', 'idx_module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_settings');
    }
};
