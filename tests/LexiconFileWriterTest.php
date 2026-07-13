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

    public function test_it_skips_when_lexicon_hash_unchanged_even_if_disk_differs(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        $path = $base.'/en/domains/artworks.php';
        file_put_contents($path, "<?php\n\nreturn ['artwork' => ['old' => 'value']];\n");
        $mtimeBefore = filemtime($path);

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

        $this->assertSame([], $outcome['written']);
        $this->assertSame(1, $outcome['skipped']);
        $this->assertSame($mtimeBefore, filemtime($path));
        $this->assertStringContainsString("'old'", (string) file_get_contents($path));
    }

    public function test_it_writes_only_when_lexicon_hash_changes(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        @mkdir($base.'/en/common', 0777, true);
        file_put_contents($base.'/en/domains/artworks.php', "<?php\n\nreturn ['artwork' => ['a' => '1']];\n");
        file_put_contents($base.'/en/common/alerts.php', "<?php\n\nreturn ['x' => 'y'];\n");

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
        $this->assertStringContainsString("'x'", (string) file_get_contents($base.'/en/common/alerts.php'));
    }

    public function test_baseline_records_hashes_without_writing(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/common', 0777, true);
        $alerts = $base.'/en/common/alerts.php';
        file_put_contents($alerts, "<?php\n\nreturn ['keep' => 'me'];\n");
        $mtimeBefore = filemtime($alerts);

        $state = new PullStateStore($base.'/pull-state.json');
        $writer = new LexiconFileWriter(pullState: $state);
        $outcome = $writer->write([
            [
                'language' => 'en',
                'area' => 'common.alerts',
                'relative_path' => 'common/alerts.php',
                'hash' => 'hash-alerts-1',
                'content' => ['different' => 'from disk'],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
        ], baseline: true);

        $this->assertSame([], $outcome['written']);
        $this->assertSame(1, $outcome['skipped']);
        $this->assertSame($mtimeBefore, filemtime($alerts));
        $this->assertSame(['en/common/alerts.php' => 'hash-alerts-1'], $state->hashes());
    }
}
