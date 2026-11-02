<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An optional end date on a staff grant. `expires_at` is the last second of the chosen Zulu day (UTC). With
     * `renew_every_days` null the grant ends there for good: the scheduled `portal:expire-staff-grants` revokes it. With
     * a period it lapses there and is kept until someone renews it. `expiry_set_by` is who last set the date, recorded
     * as the actor of the automatic revoke. Existing grants keep both null and work as before.
     */
    public function up(): void
    {
        Schema::table('portal_role_grants', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('unit_id');
            $table->unsignedSmallInteger('renew_every_days')->nullable()->after('expires_at');
            $table->foreignId('expiry_set_by')->nullable()->after('renew_every_days')->constrained('users')->nullOnDelete();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('portal_role_grants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('expiry_set_by');
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['expires_at', 'renew_every_days']);
        });
    }
};
