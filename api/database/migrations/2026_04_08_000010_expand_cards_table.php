<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('image_uri');
        });

        Schema::table('cards', function (Blueprint $table) {
            // Front-face images
            $table->string('image_small')->nullable()->after('collector_number');
            $table->string('image_normal')->nullable()->after('image_small');
            $table->string('image_large')->nullable()->after('image_normal');

            // Back-face images (DFC only)
            $table->string('image_small_back')->nullable()->after('image_large');
            $table->string('image_normal_back')->nullable()->after('image_small_back');
            $table->string('image_large_back')->nullable()->after('image_normal_back');

            // Front-face card data
            $table->text('oracle_text')->nullable()->after('image_large_back');
            $table->string('mana_cost')->nullable()->after('oracle_text');
            $table->string('type_line')->nullable()->after('mana_cost');
            $table->string('power')->nullable()->after('type_line');
            $table->string('toughness')->nullable()->after('power');
            $table->string('loyalty')->nullable()->after('toughness');

            // Back-face card data
            $table->text('oracle_text_back')->nullable()->after('loyalty');
            $table->string('mana_cost_back')->nullable()->after('oracle_text_back');
            $table->string('type_line_back')->nullable()->after('mana_cost_back');

            // Metadata
            $table->string('rarity')->nullable()->after('type_line_back');
            $table->boolean('is_dfc')->default(false)->after('rarity');
            $table->json('legalities')->nullable()->after('is_dfc');
        });
    }

    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn([
                'image_small',
                'image_normal',
                'image_large',
                'image_small_back',
                'image_normal_back',
                'image_large_back',
                'oracle_text',
                'mana_cost',
                'type_line',
                'power',
                'toughness',
                'loyalty',
                'oracle_text_back',
                'mana_cost_back',
                'type_line_back',
                'rarity',
                'is_dfc',
                'legalities',
            ]);
        });

        Schema::table('cards', function (Blueprint $table) {
            $table->string('image_uri')->nullable()->after('collector_number');
        });
    }
};
