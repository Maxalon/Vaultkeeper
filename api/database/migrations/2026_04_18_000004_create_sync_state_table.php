<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_state', function (Blueprint $table) {
            // Tiny key/value store for global sync metadata
            // (e.g. last_migration_check). Survives cache:clear so we
            // never accidentally re-process the entire migration history.
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_state');
    }
};
