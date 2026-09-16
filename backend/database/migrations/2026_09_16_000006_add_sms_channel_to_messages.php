<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('channel', 10)->default('EMAIL')->after('type');
            $table->string('purpose', 20)->default('SERVICE')->after('channel');
            $table->text('sms_body')->nullable()->after('message');
            $table->unsignedInteger('recipient_count')->nullable()->after('purpose');
            $table->decimal('sms_cost', 10, 2)->nullable()->after('recipient_count');
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->foreignId('message_id')->nullable()->after('order_id')->constrained('messages')->nullOnDelete();
            $table->index(['message_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropIndex(['message_id', 'status']);
            $table->dropConstrainedForeignId('message_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['channel', 'purpose', 'sms_body', 'recipient_count', 'sms_cost']);
        });
    }
};
