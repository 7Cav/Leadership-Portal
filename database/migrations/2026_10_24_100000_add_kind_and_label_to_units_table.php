<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff, ELOA, Reservist and New Recruit placement (decided 2026-10-24). `type` gains `group`, a node off the line
     * (mirrors `App\Models\UnitType`), and `kind` says which: `line` for every echelon of the chain of command, else
     * `staff`, `eloa`, `reserve` or `recruit` (`App\Models\UnitKind`). Every unit that exists now is on the line, which
     * the default writes. `label` is an optional game or team label an Admin sets by hand.
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->enum('type', ['regiment', 'battalion', 'company', 'platoon', 'section', 'group'])->change();
        });

        Schema::table('units', function (Blueprint $table) {
            $table->string('kind', 16)->default('line')->after('type')->index();
            $table->string('label', 40)->nullable()->after('callsign');
        });
    }

    /**
     * Refused while any group exists: the narrower enum cannot hold those rows, and deleting the groups would unplace
     * everyone in them, which is not a rollback's call.
     */
    public function down(): void
    {
        $groups = DB::table('units')->where('type', 'group')->count();

        if ($groups > 0) {
            throw new RuntimeException("Cannot roll back unit kinds: {$groups} group unit(s) still exist. Delete them first, then roll back again.");
        }

        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn(['kind', 'label']);
        });

        Schema::table('units', function (Blueprint $table) {
            $table->enum('type', ['regiment', 'battalion', 'company', 'platoon', 'section'])->change();
        });
    }
};
