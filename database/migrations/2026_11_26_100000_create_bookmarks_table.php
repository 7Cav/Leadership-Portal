<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The pages a person bookmarked for the sidebar's Bookmarks section: a route name from the allowlist in
     * `App\Bookmarks\BookmarkablePages` and its parameters, never a raw address. `params` is the parameters as
     * canonical JSON text (keys sorted), a string rather than a JSON column so it can sit in the unique index.
     * `label` is the name the page had when it was bookmarked; the sidebar shows the current one.
     */
    public function up(): void
    {
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('route', 64);
            $table->string('params', 255);
            $table->string('label', 120);
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['user_id', 'route', 'params']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
    }
};
