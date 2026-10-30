<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the leader filled in when the portal generated the record's PDF from the regiment's form (7CAV-R-042 for a
     * DCS, 7CAV-R-043 for an LOR), kept so the document can be made again and shown. Null for an uploaded document.
     * Portal-owned (AD-22), set once when the record is filed.
     */
    public function up(): void
    {
        Schema::table('counseling_records', function (Blueprint $table) {
            $table->json('form_data')->nullable()->after('document_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('counseling_records', function (Blueprint $table) {
            $table->dropColumn('form_data');
        });
    }
};
