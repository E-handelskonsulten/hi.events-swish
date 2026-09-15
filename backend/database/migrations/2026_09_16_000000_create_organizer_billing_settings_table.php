<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_billing_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organizer_id');
            $table->boolean('sms_enabled')->default(false);
            $table->string('sms_sender_name', 11)->nullable();
            $table->decimal('platform_fee_per_ticket', 10, 2)->default(6.00);
            $table->decimal('sms_fee_per_message', 10, 2)->default(0.50);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organizer_id')
                ->references('id')
                ->on('organizers')
                ->onDelete('cascade');

            $table->unique('organizer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer_billing_settings');
    }
};
