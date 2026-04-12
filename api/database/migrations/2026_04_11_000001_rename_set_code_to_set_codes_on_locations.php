<?php

use App\Models\Location;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->renameColumn('set_code', 'set_codes');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->text('set_codes')->nullable()->change();
        });

        // Normalize pre-existing rows to the new comma-joined format by
        // recomputing from each location's collection entries.
        Location::query()->chunkById(200, function ($locations) {
            foreach ($locations as $loc) {
                $loc->refreshSetCodes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('set_codes')->nullable()->change();
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->renameColumn('set_codes', 'set_code');
        });
    }
};
