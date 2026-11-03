<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The secret in a unit's roll call result image URLs (#641). The forum fetches those images without a portal login,
     * so the URL itself is the key: 40 random characters, one per unit, never derived from anything. Every unit that
     * exists now gets one here; units created later get theirs from the model.
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->string('rollcall_image_token', 40)->nullable()->unique();
        });

        DB::table('units')->orderBy('id')->each(function (object $unit) {
            DB::table('units')->where('id', $unit->id)->update(['rollcall_image_token' => Str::random(40)]);
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropUnique(['rollcall_image_token']);
            $table->dropColumn('rollcall_image_token');
        });
    }
};
