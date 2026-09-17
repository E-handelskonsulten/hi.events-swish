<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes_and_fees', function (Blueprint $table) {
            // Swedish rule: a service fee on a ticket carries the ticket's VAT rate.
            // Off for existing fees so past configuration is untouched; new fees start on.
            $table->boolean('inherits_ticket_vat')->default(false)->after('is_inclusive');
        });
    }

    public function down(): void
    {
        Schema::table('taxes_and_fees', function (Blueprint $table) {
            $table->dropColumn('inherits_ticket_vat');
        });
    }
};
