<?php

namespace A21\LexiconClient\Http;

use A21\LexiconClient\Support\LexiconConfigGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class LexiconHttpClient
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->request()
            ->get($this->endpoint('/client/status'))
            ->throw()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function export(array $payload): array
    {
        return $this->request()
            ->post($this->endpoint('/client/export'), $payload)
            ->throw()
            ->json('data');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function import(array $payload): array
    {
        return $this->request(timeout: (int) config('lexicon.http.import_timeout', 180))
            ->post($this->endpoint('/client/import'), $payload)
            ->throw()
            ->json('data');
    }

    private function request(?int $timeout = null): PendingRequest
    {
        $this->assertConfigured();

        $timeout ??= (int) config('lexicon.http.timeout', 30);
        $retryTimes = (int) config('lexicon.http.retry_times', 2);
        $retrySleep = (int) config('lexicon.http.retry_sleep_ms', 200);

        return Http::baseUrl(rtrim((string) $this->config['api_url'], '/').'/api/lexicon')
            ->timeout($timeout)
            ->retry($retryTimes, $retrySleep, fn ($exception) => $exception instanceof RequestException && $exception->response?->serverError())
            ->withHeaders([
                'X-Lexicon-Client' => (string) $this->config['client_code'],
                'X-Lexicon-Project' => (string) $this->config['project_code'],
                'X-Lexicon-Environment' => (string) $this->config['environment'],
                'Authorization' => 'Bearer '.(string) $this->config['secret'],
                'Accept' => 'application/json',
            ]);
    }

    private function endpoint(string $path): string
    {
        return ltrim($path, '/');
    }

    private function assertConfigured(): void
    {
        LexiconConfigGuard::assertReady($this->config);
    }
}
