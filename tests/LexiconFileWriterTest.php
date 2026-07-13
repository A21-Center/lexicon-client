<?php

namespace A21\LexiconClient\Tests;

use A21\LexiconClient\Files\LexiconFileWriter;
use A21\LexiconClient\Files\PullStateStore;
use Orchestra\Testbench\TestCase;

class LexiconFileWriterTest extends TestCase
{
    public function test_it_writes_nested_json_files_using_pattern(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        $state = new PullStateStore($base.'/pull-state.json');
        $writer = new LexiconFileWriter(pullState: $state);

        $outcome = $writer->write([
            [
                'language' => 'fr',
                'area' => 'catalog',
                'hash' => 'hash-catalog-1',
                'content' => ['ui' => ['label' => 'Art']],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{area}.json',
            'format' => 'nested_json',
        ]);

        $this->assertCount(1, $outcome['written']);
        $this->assertSame(0, $outcome['skipped']);
        $this->assertFileExists($base.'/fr/catalog.json');
    }

    public function test_it_writes_when_hash_unchanged_but_local_file_lags(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        $path = $base.'/en/domains/artworks.php';
        file_put_contents($path, "<?php\n\nreturn ['artwork' => ['old' => 'value']];\n");

        $state = new PullStateStore($base.'/pull-state.json');
        $state->save(['en/domains/artworks.php' => 'hash-artworks-1']);

        $writer = new LexiconFileWriter(pullState: $state);
        $outcome = $writer->write([
            [
                'language' => 'en',
                'area' => 'domains.artworks',
                'relative_path' => 'domains/artworks.php',
                'hash' => 'hash-artworks-1',
                'content' => [
                    'artwork' => [
                        'test_sync' => 'test sync',
                    ],
                ],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
        ]);

        $this->assertSame([$path], $outcome['written']);
        $parsed = include $path;
        $this->assertSame('test sync', $parsed['artwork']['test_sync']);
    }

    public function test_it_writes_only_changed_area_when_other_local_matches(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        @mkdir($base.'/en/common', 0777, true);
        file_put_contents($base.'/en/domains/artworks.php', "<?php\n\nreturn ['artwork' => ['a' => '1']];\n");
        file_put_contents($base.'/en/common/alerts.php', "<?php\n\nreturn ['check' => 'same'];\n");

        $state = new PullStateStore($base.'/pull-state.json');
        $state->save([
            'en/domains/artworks.php' => 'hash-artworks-1',
            'en/common/alerts.php' => 'hash-alerts-1',
        ]);

        $writer = new LexiconFileWriter(pullState: $state);
        $outcome = $writer->write([
            [
                'language' => 'en',
                'area' => 'domains.artworks',
                'relative_path' => 'domains/artworks.php',
                'hash' => 'hash-artworks-2',
                'content' => ['artwork' => ['test_sync' => 'test sync']],
            ],
            [
                'language' => 'en',
                'area' => 'common.alerts',
                'relative_path' => 'common/alerts.php',
                'hash' => 'hash-alerts-1',
                'content' => ['check' => 'same'],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
        ]);

        $this->assertSame([$base.'/en/domains/artworks.php'], $outcome['written']);
        $this->assertSame(1, $outcome['skipped']);
        $parsed = include $base.'/en/domains/artworks.php';
        $this->assertSame('test sync', $parsed['artwork']['test_sync']);
        $this->assertStringContainsString("'check'", (string) file_get_contents($base.'/en/common/alerts.php'));
    }

    public function test_baseline_only_stores_hashes_for_matching_local_files(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/common', 0777, true);
        @mkdir($base.'/en/domains', 0777, true);
        file_put_contents($base.'/en/common/alerts.php', "<?php\n\nreturn ['keep' => 'me'];\n");
        file_put_contents($base.'/en/domains/artworks.php', "<?php\n\nreturn ['old' => 'local'];\n");

        $state = new PullStateStore($base.'/pull-state.json');
        $writer = new LexiconFileWriter(pullState: $state);
        $outcome = $writer->write([
            [
                'language' => 'en',
                'area' => 'common.alerts',
                'relative_path' => 'common/alerts.php',
                'hash' => 'hash-alerts-1',
                'content' => ['keep' => 'me'],
            ],
            [
                'language' => 'en',
                'area' => 'domains.artworks',
                'relative_path' => 'domains/artworks.php',
                'hash' => 'hash-artworks-1',
                'content' => ['artwork' => ['test_sync' => 'test sync']],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
        ], baseline: true);

        $this->assertSame([], $outcome['written']);
        $this->assertSame(['en/common/alerts.php' => 'hash-alerts-1'], $state->hashes());
    }
}
