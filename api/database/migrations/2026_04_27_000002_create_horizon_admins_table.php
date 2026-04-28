<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row credential store for the Horizon dashboard.
 *
 * The dashboard is gated by an environment-local password chosen via the
 * /horizon-setup web flow on first access. We don't store a username — one
 * password per environment is plenty for an ops dashboard, and it sidesteps
 * the "what username should I have used?" recovery problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horizon_admins', function (Blueprint $table) {
            $table->id();
            $table->string('password_hash');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horizon_admins');
    }
};
