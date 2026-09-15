<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_summary_runs', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->string('recipient', 255);
            $table->unsignedInteger('organizer_count')->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique('period_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_summary_runs');
    }
};
