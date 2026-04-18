<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scryfall_cards', function (Blueprint $table) {
            $table->id();
            $table->uuid('scryfall_id')->unique();
            $table->uuid('oracle_id')->index();
            $table->string('name')->index();
            // No FK to sets.code — bulk sync may upsert cards before sets;
            // syncSets() runs first in scryfall:sync-bulk to keep them aligned.
            $table->string('set_code')->index();
            $table->string('collector_number');
            $table->string('rarity');
            $table->string('layout');
            $table->boolean('is_dfc')->default(false);

            $table->string('mana_cost')->nullable();
            // Wide enough for Un-set joke CMCs (Gleemax = 1,000,000).
            $table->decimal('cmc', 12, 2)->nullable();
            $table->json('colors')->nullable();
            $table->json('color_identity')->nullable();
            $table->string('type_line')->nullable();
            $table->text('oracle_text')->nullable();
            $table->string('power')->nullable();
            $table->string('toughness')->nullable();
            $table->string('loyalty')->nullable();

            $table->json('legalities')->nullable();
            $table->json('keywords')->nullable();

            $table->string('image_small')->nullable();
            $table->string('image_normal')->nullable();
            $table->string('image_large')->nullable();
            $table->string('image_small_back')->nullable();
            $table->string('image_normal_back')->nullable();
            $table->string('image_large_back')->nullable();

            $table->string('mana_cost_back')->nullable();
            $table->string('type_line_back')->nullable();
            $table->text('oracle_text_back')->nullable();

            $table->integer('edhrec_rank')->nullable();
            $table->boolean('reserved')->default(false);

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['set_code', 'collector_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scryfall_cards');
    }
};
