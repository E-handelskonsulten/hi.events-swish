<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->timestamp('scheduled_for')->nullable()->after('sent_at');
            $table->index(['status', 'scheduled_for']);
            $table->index(['event_id', 'status']);
        });

        Schema::table('organizer_billing_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('sms_lead_hours')->default(3)->after('sms_sender_name');
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropIndex(['status', 'scheduled_for']);
            $table->dropIndex(['event_id', 'status']);
            $table->dropColumn('scheduled_for');
        });

        Schema::table('organizer_billing_settings', function (Blueprint $table) {
            $table->dropColumn('sms_lead_hours');
        });
    }
};
