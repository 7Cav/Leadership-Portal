<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leave now comes from the roster API (#690), which names the LOA forum a thread is in (`1-7`, `2-7`, `3-7`,
     * `acd`, `regimental`) but not the forum's section id. So `forum_node_id`, which only the forum read knows,
     * becomes optional, and `forum` keeps what the API says. ELOA, still read from the forum, keeps its node id.
     */
    public function up(): void
    {
        Schema::table('loas', function (Blueprint $table) {
            $table->unsignedInteger('forum_node_id')->nullable()->change();
            $this->keepEnums($table);
            $table->string('forum', 16)->nullable()->after('forum_node_id');
        });
    }

    public function down(): void
    {
        // A row read from the roster API has no section id, and the column cannot hold null again. Those rows are
        // a re-fetchable cache (AD-22), so they go; the next sync reads them back.
        DB::table('loas')->whereNull('forum_node_id')->delete();

        Schema::table('loas', function (Blueprint $table) {
            $table->dropColumn('forum');
            $table->unsignedInteger('forum_node_id')->nullable(false)->change();
            $this->keepEnums($table);
        });
    }

    /**
     * SQLite rebuilds the whole table to change one column, and a rebuild drops the CHECK constraint an `enum` is
     * on that driver. Declaring the two enums again in the same change puts them back (on MySQL it restates the
     * column as it already is), so the table still refuses a type or a parse status it does not know.
     */
    private function keepEnums(Blueprint $table): void
    {
        $table->enum('type', ['loa', 'eloa'])->change();
        $table->enum('parse_status', ['ok', 'needs_review'])->change();
    }
};
