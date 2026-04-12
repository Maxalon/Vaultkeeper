<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('group_id')
                ->nullable()
                ->after('user_id')
                ->constrained('location_groups')
                ->nullOnDelete();
            $table->integer('sort_order')->default(0)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn('sort_order');
        });

        Schema::dropIfExists('location_groups');
    }
};
