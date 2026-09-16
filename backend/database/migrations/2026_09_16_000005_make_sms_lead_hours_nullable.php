<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer_billing_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('sms_lead_hours')->nullable()->default(3)->change();
        });
    }

    public function down(): void
    {
        Schema::table('organizer_billing_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('sms_lead_hours')->nullable(false)->default(3)->change();
        });
    }
};
