<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes_and_fees', function (Blueprint $table) {
            $table->decimal('fixed_amount', 10, 2)->nullable()->after('rate');
            $table->string('calculation_type', 30)->change();
        });

        DB::statement('ALTER TABLE taxes_and_fees DROP CONSTRAINT IF EXISTS calculation_method_check');
        DB::statement("ALTER TABLE taxes_and_fees ADD CONSTRAINT calculation_method_check CHECK (calculation_type IN ('FIXED', 'PERCENTAGE', 'FIXED_PLUS_PERCENTAGE'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE taxes_and_fees DROP CONSTRAINT IF EXISTS calculation_method_check');
        DB::statement("ALTER TABLE taxes_and_fees ADD CONSTRAINT calculation_method_check CHECK (calculation_type IN ('FIXED', 'PERCENTAGE'))");

        Schema::table('taxes_and_fees', function (Blueprint $table) {
            $table->dropColumn('fixed_amount');
            $table->string('calculation_type', 20)->change();
        });
    }
};
