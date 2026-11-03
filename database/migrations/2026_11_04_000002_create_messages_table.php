<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every message the control center sends or meant to send: to whom, on which
 * channel, what it said, and whether it got there. It is both the log staff
 * read and the memory that stops the same reminder going out twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 40);            // expiring_30 … expired, blocked, unblocked, payment_received
            $table->string('channel', 10);         // email | whatsapp | none (nobody to write to)
            $table->string('to')->nullable();
            $table->string('locale', 5)->default('fa');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->json('params')->nullable();    // what a WhatsApp template is filled with
            $table->string('status', 10)->default('queued'); // queued | sent | failed | skipped
            $table->text('error')->nullable();
            $table->string('provider_id')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            // Names the thing a message is about ("this customer, this kind,
            // this channel, this contact, this end date"), so running the
            // reminders twice, or a day late, never sends one twice.
            $table->string('dedupe_key')->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
