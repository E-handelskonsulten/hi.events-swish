<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'event_settings_price_display_mode_check';

    public function up(): void
    {
        DB::statement('ALTER TABLE event_settings DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement(
            'ALTER TABLE event_settings ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (price_display_mode::text = ANY (ARRAY['INCLUSIVE'::text, 'EXCLUSIVE'::text, 'CHECKOUT'::text]))"
        );
    }

    public function down(): void
    {
        DB::table('event_settings')->where('price_display_mode', 'CHECKOUT')->update(['price_display_mode' => 'EXCLUSIVE']);
        DB::statement('ALTER TABLE event_settings DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement(
            'ALTER TABLE event_settings ADD CONSTRAINT '.self::CONSTRAINT
            ." CHECK (price_display_mode::text = ANY (ARRAY['INCLUSIVE'::text, 'EXCLUSIVE'::text]))"
        );
    }
};
