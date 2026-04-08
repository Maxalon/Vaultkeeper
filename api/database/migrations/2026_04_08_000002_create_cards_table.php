<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->string('scryfall_id')->unique();
            $table->string('name');
            $table->string('set_code');
            $table->string('collector_number');
            $table->string('image_uri')->nullable();
            $table->timestamp('last_scryfall_sync')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
