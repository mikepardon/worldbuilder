<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('display_name');
            $table->string('subject');
            $table->text('description')->nullable();
            $table->longText('mjml')->nullable();
            $table->longText('html')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_mailbox')->nullable();
            $table->string('reply_to_name')->nullable();
            $table->string('reply_to_mailbox')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
