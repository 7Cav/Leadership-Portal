<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Portal-owned identity link (AD-22, proposal §9.3) — NOT an account store.
        // SSO-only, so there is nothing password-shaped to breach (AD-6): no password,
        // no remember_token, no password_reset_tokens table. Identity is keyed on the
        // forum user id returned by 7cav.us OAuth; `cav_id` stays null until the Cav
        // roster enrichment (Story 1.6) fills it.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('forum_user_id')->unique();
            $table->unsignedBigInteger('cav_id')->nullable()->unique();
            $table->string('username');
            $table->string('email')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
