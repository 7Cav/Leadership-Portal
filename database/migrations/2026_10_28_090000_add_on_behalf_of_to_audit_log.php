<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-owned (AD-22), written only by the audit log's one writer (AD-16). While an Admin acts as someone (#379),
     * `actor_id` stays the Admin and `on_behalf_of_id` names the person they acted as; null for everything else, so
     * every existing row reads as before. `restrictOnDelete`, like `actor_id`: an audit row never loses who it names.
     */
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->foreignId('on_behalf_of_id')->nullable()->after('actor_id')->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropConstrainedForeignId('on_behalf_of_id');
        });
    }
};
