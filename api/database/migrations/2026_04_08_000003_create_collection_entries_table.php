<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Explicit string FK to cards.scryfall_id (NOT foreignId — cards.scryfall_id is a string column)
            $table->string('scryfall_id');
            $table->foreign('scryfall_id')->references('scryfall_id')->on('cards');

            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->enum('condition', ['NM', 'LP', 'MP', 'HP', 'DMG'])->default('NM');
            $table->boolean('foil')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('scryfall_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_entries');
    }
};
