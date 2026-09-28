<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_subscriber_id')->nullable()->index()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('email_campaign_id')->nullable()->index()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->string('recipient_email');
            $table->string('template_reference')->nullable();
            $table->string('subject');
            $table->string('domain')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('status')->default('sent');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->json('clicked_links')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_messages');
    }
};
