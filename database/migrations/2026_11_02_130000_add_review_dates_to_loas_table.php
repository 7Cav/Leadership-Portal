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
        // The leave dates a post that needs review still gives (both read, the end not before the start, the span cut
        // to the 14 days an LOA can last). Never `starts_at`/`ends_at`: the row asserts no leave. Coverage uses them only
        // to place the needs-review shield on the weeks of the leave, instead of the week the thread was posted in.
        Schema::table('loas', function (Blueprint $table) {
            $table->date('review_starts_at')->nullable()->after('review_reason');
            $table->date('review_ends_at')->nullable()->after('review_starts_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loas', function (Blueprint $table) {
            $table->dropColumn(['review_starts_at', 'review_ends_at']);
        });
    }
};
