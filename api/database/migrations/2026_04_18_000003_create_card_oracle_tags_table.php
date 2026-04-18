<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_oracle_tags', function (Blueprint $table) {
            $table->id();
            $table->uuid('oracle_id')->index();
            $table->string('tag')->index();
            $table->timestamps();

            $table->unique(['oracle_id', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_oracle_tags');
    }
};
