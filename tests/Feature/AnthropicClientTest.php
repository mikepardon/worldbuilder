<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AnthropicClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AnthropicClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_timeout_surfaces_a_friendly_retryable_error(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(function (): void {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The AI took too long to respond — please try again.');

        app(AnthropicClient::class)->message('You are a bot.', 'Hello?');
    }

    public function test_a_non_timeout_error_status_reports_the_service_error(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The AI service is temporarily unavailable — please try again.');

        app(AnthropicClient::class)->message('You are a bot.', 'Hello?');
    }
}
