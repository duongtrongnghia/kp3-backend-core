<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metaables', function (Blueprint $table) {
            $table->id();
            $table->string('metaable_type', 50);
            $table->unsignedBigInteger('metaable_id');
            $table->string('key', 100);
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->timestamps();

            $table->unique(['metaable_type', 'metaable_id', 'key'], 'uk_meta_unique');
            $table->index(['metaable_type', 'metaable_id'], 'idx_metaable');
            $table->index(['metaable_type', 'key'], 'idx_key_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metaables');
    }
};
