<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer_swish_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organizer_id');
            $table->boolean('enabled')->default(false);
            $table->string('environment', 20)->default('mss');
            $table->string('payee_alias', 20)->nullable();
            $table->string('cert_path', 500)->nullable();
            $table->string('key_path', 500)->nullable();
            $table->text('key_passphrase')->nullable();
            $table->string('ca_path', 500)->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->text('last_verification_error')->nullable();
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
        Schema::dropIfExists('organizer_swish_settings');
    }
};
