<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Portal-owned (AD-22): which Regimental HQ billets make their holder an Admin (decided by the S6, 2026-10-28). One
     * row per Cav position, keyed on its stable `cav_position_id`, never on the title, which Cav may rename. An existing
     * Admin confirms a billet's title and group; its holder is an Admin while they serve on the regiment and the billet
     * still carries exactly the names that were confirmed. The org import and the roster sync record the names they see
     * as `proposed_*` and put the row back to `pending` when they differ, so a renamed billet needs confirming again.
     * Decided only by the audited `DecideAdminBillet` Command (AD-16).
     *
     * Every Regimental HQ position the roster places on the regiment today starts `pending`: no billet is ever approved
     * without an Admin. The import adds the rest, vacant ones included, on its next run.
     */
    public function up(): void
    {
        Schema::create('admin_billets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cav_position_id')->unique();
            // What an Admin confirmed, null until the first approval. Only an approval changes these.
            $table->string('title')->nullable();
            $table->string('group_title')->nullable();
            // What the import or the roster sync last saw. The group is null until the import has seen the billet.
            $table->string('proposed_title')->nullable();
            $table->string('proposed_group_title')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            // The last decision: who made it, with the Admin acting as them or the billet an Admin held (#379), as for
            // every other who-column. `restrictOnDelete` for people, `nullOnDelete` for the billet's unit.
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by_admin')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_as_unit')->nullable()->constrained('units')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->timestamps();
        });

        $regiments = DB::table('units')->where('type', 'regiment')->pluck('id');
        if ($regiments->isEmpty()) {
            return;
        }

        $now = now();
        DB::table('troopers')
            ->whereIn('primary_section_id', $regiments->all())
            ->whereNotNull('cav_position_id')
            ->orderBy('id')
            ->get(['cav_position_id', 'billet'])
            ->unique('cav_position_id')
            ->each(fn ($trooper) => DB::table('admin_billets')->insert([
                'cav_position_id' => $trooper->cav_position_id,
                'proposed_title' => $trooper->billet,
                // The only Cav group the import places on the regiment (`CommandBillet::headquartersOf()`).
                'proposed_group_title' => 'Regimental HQ',
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_billets');
    }
};
