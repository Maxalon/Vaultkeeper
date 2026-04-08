<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deck_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();

            // Explicit string FK to cards.scryfall_id
            $table->string('scryfall_id');
            $table->foreign('scryfall_id')->references('scryfall_id')->on('cards');

            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('is_sideboard')->default(false);
            $table->boolean('wanted')->default(false);
            $table->foreignId('physical_copy_id')->nullable()->constrained('collection_entries')->nullOnDelete();
            $table->timestamps();

            $table->index('scryfall_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deck_entries');
    }
};
