<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swish_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->string('instruction_uuid', 32);
            $table->string('environment', 20);
            $table->string('flow', 20);
            $table->string('payee_alias', 20);
            $table->string('payer_alias', 20)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->string('status', 30)->default('CREATED');
            $table->string('payment_request_token', 100)->nullable();
            $table->string('location_url', 500)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->string('error_code', 20)->nullable();
            $table->text('error_message')->nullable();
            $table->text('flag_reason')->nullable();
            $table->jsonb('callback_payload')->nullable();
            $table->timestamp('date_paid')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->unsignedInteger('poll_attempts')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique('instruction_uuid');
            $table->index('order_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swish_payments');
    }
};
