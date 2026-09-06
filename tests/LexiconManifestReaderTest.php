<?php

namespace A21\LexiconClient\Tests;

use A21\LexiconClient\Manifest\LexiconManifestReader;
use Orchestra\Testbench\TestCase;

class LexiconManifestReaderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\A21\LexiconClient\LexiconClientServiceProvider::class];
    }

    public function test_it_merges_manifest_output_with_config(): void
    {
        $manifestPath = sys_get_temp_dir().'/lexicon-manifest-'.uniqid().'.json';
        file_put_contents($manifestPath, json_encode([
            'client' => 'hub',
            'project' => 'hub',
            'languages' => ['fr'],
            'areas' => ['catalog'],
            'output' => [
                'pattern' => '{locale}/{area}.json',
                'format' => 'nested_json',
            ],
        ]));

        config([
            'lexicon.manifest' => $manifestPath,
            'lexicon.api_url' => 'https://lexicon.test',
            'lexicon.secret' => 'lex_sk_live_testsecret12',
        ]);

        $merged = (new LexiconManifestReader())->mergedConfig();

        $this->assertSame('hub', $merged['client_code']);
        $this->assertSame(['fr'], $merged['languages']);
        $this->assertSame('nested_json', $merged['output']['format']);
    }
}
