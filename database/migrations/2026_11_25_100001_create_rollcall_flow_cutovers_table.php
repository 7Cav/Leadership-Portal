<?php

use App\Support\Time\WeekOf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the new roll call flow starts (#703): the first Cav week (its Saturday, AD-11) that starts after this
     * migration runs, so the release that brings the flow decides it and the week running at release finishes on the
     * three steps. One row, read by `Repositories\RollcallFlow`; `portal.rollcall_flow_v2_from` can override it.
     */
    public function up(): void
    {
        Schema::create('rollcall_flow_cutovers', function (Blueprint $table) {
            $table->id();
            $table->date('first_week');
            $table->timestamps();
        });

        $now = CarbonImmutable::now('UTC');

        DB::table('rollcall_flow_cutovers')->insert([
            'first_week' => WeekOf::next(WeekOf::start($now))->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('rollcall_flow_cutovers');
    }
};
