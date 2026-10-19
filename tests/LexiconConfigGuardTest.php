<?php

namespace A21\LexiconClient\Tests;

use A21\LexiconClient\Support\LexiconConfigGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class LexiconConfigGuardTest extends TestCase
{
    public function test_assert_ready_passes_when_all_credentials_present(): void
    {
        LexiconConfigGuard::assertReady([
            'api_url' => 'https://lexicon-api.example.com',
            'client_code' => 'studio',
            'project_code' => 'studio',
            'secret' => 'secret-value',
        ]);

        $this->assertTrue(true);
    }

    public function test_assert_ready_fails_when_secret_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LEXICON_CLIENT_SECRET');

        LexiconConfigGuard::assertReady([
            'api_url' => 'https://lexicon-api.example.com',
            'client_code' => 'studio',
            'project_code' => 'studio',
            'secret' => '',
        ]);
    }

    public function test_missing_keys_lists_env_names(): void
    {
        $missing = LexiconConfigGuard::missingKeys([
            'api_url' => '',
            'client_code' => 'studio',
            'project_code' => null,
            'secret' => '   ',
        ]);

        $this->assertSame([
            'LEXICON_API_URL',
            'LEXICON_PROJECT_CODE',
            'LEXICON_CLIENT_SECRET',
        ], $missing);
    }
}
