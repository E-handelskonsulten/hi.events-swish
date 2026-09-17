<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes_and_fees', function (Blueprint $table) {
            // Swedish VAT is part of the ticket price: reported as "varav moms", never added on top.
            $table->boolean('is_inclusive')->default(false)->after('fixed_amount');
        });
    }

    public function down(): void
    {
        Schema::table('taxes_and_fees', function (Blueprint $table) {
            $table->dropColumn('is_inclusive');
        });
    }
};
