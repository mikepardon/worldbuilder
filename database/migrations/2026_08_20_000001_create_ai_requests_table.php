<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An in-flight (or finished) asynchronous AI request. The web request enqueues a job and returns a handle;
 * the queue worker runs the model call and stores the result here; the browser polls for it. This keeps a
 * slow generation off the web request entirely, so it can never hit the Cloudflare gateway timeout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('feature');
            $table->string('status')->default('pending'); // pending | done | failed
            $table->jsonb('result')->nullable();           // the payload handed back to the browser
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
