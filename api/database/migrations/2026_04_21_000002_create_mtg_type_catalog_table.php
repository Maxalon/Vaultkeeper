<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mtg_type_catalog', function (Blueprint $table) {
            $table->id();
            // 'supertype', 'card_type', or '<permanent>_subtype'
            // (creature_subtype, planeswalker_subtype, land_subtype,
            //  artifact_subtype, enchantment_subtype, spell_subtype).
            $table->string('category', 40);
            $table->string('name', 80);
            $table->boolean('is_multi_word')->default(false);
            $table->timestamps();

            $table->unique(['category', 'name']);
            $table->index('is_multi_word');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mtg_type_catalog');
    }
};
