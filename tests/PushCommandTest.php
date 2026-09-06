<?php

namespace A21\LexiconClient\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class PushCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\A21\LexiconClient\LexiconClientServiceProvider::class];
    }

    private function configureManifest(array $extractors): void
    {
        $manifestPath = sys_get_temp_dir().'/lexicon-push-'.uniqid('', true).'.json';
        file_put_contents($manifestPath, json_encode([
            'client' => 'studio-backend',
            'project' => 'studio',
            'extractors' => $extractors,
        ]));

        config([
            'lexicon.manifest' => $manifestPath,
            'lexicon.api_url' => 'https://lexicon.test',
            'lexicon.client_code' => 'studio-backend',
            'lexicon.project_code' => 'studio',
            'lexicon.secret' => 'lex_sk_live_testsecret12',
            'lexicon.environment' => 'local',
        ]);
    }

    private function bladeDir(): string
    {
        $dir = sys_get_temp_dir().'/lexicon-push-views-'.uniqid('', true);
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/welcome.blade.php', "<h1>{{ __('Welcome home') }}</h1>");

        return $dir;
    }

    public function test_push_sends_extracted_entries_to_translate(): void
    {
        Http::fake([
            '*/integrations/translate' => Http::response([
                'data' => ['status' => 'created', 'layer' => 'template'],
            ], 200),
        ]);

        $this->configureManifest([
            [
                'group' => 'emails',
                'type' => 'blade',
                'layer' => 'template',
                'application' => 'studio',
                'module' => 'emails',
                'area' => 'emails',
                'paths' => [$this->bladeDir()],
            ],
        ]);

        $this->artisan('lexicon:push')
            ->assertExitCode(0);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/integrations/translate')
                && $request['layer'] === 'template'
                && $request['area_code'] === 'emails'
                && $request['source_text'] === 'Welcome home';
        });
    }

    public function test_push_dry_run_sends_nothing(): void
    {
        Http::fake();

        $this->configureManifest([
            [
                'group' => 'emails',
                'type' => 'blade',
                'layer' => 'template',
                'application' => 'studio',
                'module' => 'emails',
                'area' => 'emails',
                'paths' => [$this->bladeDir()],
            ],
        ]);

        $this->artisan('lexicon:push', ['--dry-run' => true])
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_push_respects_group_filter(): void
    {
        Http::fake([
            '*/integrations/translate' => Http::response(['data' => ['status' => 'created']], 200),
        ]);

        $dir = $this->bladeDir();

        $this->configureManifest([
            ['group' => 'emails', 'type' => 'blade', 'area' => 'emails', 'paths' => [$dir]],
            ['group' => 'notifications', 'type' => 'blade', 'area' => 'notifications', 'paths' => [$dir]],
        ]);

        $this->artisan('lexicon:push', ['--group' => ['emails']])
            ->assertExitCode(0);

        Http::assertSentCount(1);
    }

    public function test_extract_command_reports_summary(): void
    {
        $this->configureManifest([
            [
                'group' => 'emails',
                'type' => 'blade',
                'layer' => 'template',
                'area' => 'emails',
                'paths' => [$this->bladeDir()],
            ],
        ]);

        $this->artisan('lexicon:extract')
            ->expectsOutputToContain('Lexicon extraction')
            ->assertExitCode(0);
    }
}
