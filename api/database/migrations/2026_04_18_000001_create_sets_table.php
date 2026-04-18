<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sets', function (Blueprint $table) {
            $table->id();
            $table->uuid('scryfall_id')->unique();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('set_type');
            $table->date('released_at')->nullable();
            $table->unsignedInteger('card_count')->default(0);
            // Local count of how many scryfall_cards rows we have for this set.
            // Compared against card_count by the daily check to detect drift.
            $table->unsignedInteger('our_card_count')->default(0);
            $table->string('icon_svg_uri')->nullable();
            $table->string('search_uri', 1024);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sets');
    }
};
