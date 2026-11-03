<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Which channels this person agrees to be written to on. WhatsApp
            // in particular must not be used on someone who has said no.
            $table->boolean('wants_email')->default(true)->after('is_primary');
            $table->boolean('wants_whatsapp')->default(true)->after('wants_email');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['wants_email', 'wants_whatsapp']);
        });
    }
};
