<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The roll call statuses of 2026-10-30. Practice (`attendance.status`): `attached` (trained at another section's
     * practice) merges into `makeup`, and `excused` is new. Signature (`attendance.rollcall`): `makeup_requested` and
     * `excused` are new; `cannot_attend` keeps its value and is only relabelled "Will not attend". The stored values of
     * the statuses whose meaning is unchanged stay as they were.
     *
     * The ids of the rows that were `attached` are kept in `attendance_attached_rows`, so a rollback can put them back.
     *
     * The value lists mirror `App\Models\AttendanceStatus`, `AttendanceSource` and `RollcallSignature`. All three enum
     * columns are restated in one change: SQLite (the test DB) rebuilds the table to change a column and the rebuild
     * drops the CHECK behind any enum it is not told about. MySQL alters them in place. The widening runs before the
     * update and the narrowing after it, so MySQL in strict mode never sees a value its column cannot hold.
     */
    public function up(): void
    {
        Schema::create('attendance_attached_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('attendance_id')->primary();
        });

        $this->columns(['present', 'missed', 'makeup', 'attached', 'excused', 'loa'], ['unsigned', 'will_attend', 'cannot_attend', 'makeup_requested', 'excused']);

        DB::table('attendance_attached_rows')->insertUsing(
            ['attendance_id'],
            DB::table('attendance')->select('id')->where('status', 'attached'),
        );
        DB::table('attendance')->where('status', 'attached')->update(['status' => 'makeup']);

        $this->columns(['present', 'missed', 'makeup', 'excused', 'loa'], ['unsigned', 'will_attend', 'cannot_attend', 'makeup_requested', 'excused']);
    }

    /**
     * Refused while any row holds a status the old columns cannot hold (`excused` on either column, or
     * `makeup_requested`): rewriting what a leader recorded is not a rollback's call, the rule the WARDOGS and guests
     * rollbacks follow. Otherwise the rows that were `attached` and still read `makeup` go back to `attached`; one a
     * leader changed since keeps its new mark.
     */
    public function down(): void
    {
        $newer = DB::table('attendance')
            ->where('status', 'excused')
            ->orWhereIn('rollcall', ['makeup_requested', 'excused'])
            ->count();

        if ($newer > 0) {
            throw new RuntimeException("Cannot roll back the roll call statuses: {$newer} attendance row(s) are marked Excused or Make-up requested. Change them on the roll call first, then roll back again.");
        }

        $this->columns(['present', 'missed', 'makeup', 'attached', 'excused', 'loa'], ['unsigned', 'will_attend', 'cannot_attend', 'makeup_requested', 'excused']);

        DB::table('attendance')
            ->where('status', 'makeup')
            ->whereIn('id', DB::table('attendance_attached_rows')->select('attendance_id'))
            ->update(['status' => 'attached']);

        $this->columns(['present', 'missed', 'makeup', 'attached', 'loa'], ['unsigned', 'will_attend', 'cannot_attend']);

        Schema::dropIfExists('attendance_attached_rows');
    }

    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $signatures
     */
    private function columns(array $statuses, array $signatures): void
    {
        Schema::table('attendance', function (Blueprint $table) use ($statuses, $signatures) {
            $table->enum('status', $statuses)->nullable()->change();
            $table->enum('source', ['manual', 'auto'])->nullable()->change();
            $table->enum('rollcall', $signatures)->default('unsigned')->change();
        });
    }
};
