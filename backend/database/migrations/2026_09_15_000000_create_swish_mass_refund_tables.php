<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swish_mass_refund_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('account_id')->constrained('accounts')->onDelete('cascade');
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('initiated_by_name', 255)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->string('currency', 3);
            $table->unsignedInteger('total_orders')->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->unsignedInteger('manual_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('requested_count')->default(0);
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->decimal('succeeded_amount', 14, 2)->default(0);
            $table->boolean('notify_buyers')->default(true);
            $table->boolean('cancel_orders')->default(true);
            $table->jsonb('summary')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['event_id', 'status']);
        });

        Schema::create('swish_mass_refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('swish_mass_refund_runs')->onDelete('cascade');
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('swish_refund_id')->nullable()->constrained('swish_refunds')->onDelete('set null');
            $table->string('order_public_id', 50);
            $table->string('buyer_name', 255)->nullable();
            $table->string('buyer_email', 255)->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['run_id', 'order_id']);
            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('swish_mass_refund_items');
        Schema::dropIfExists('swish_mass_refund_runs');
    }
};
