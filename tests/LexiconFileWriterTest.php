<?php

namespace A21\LexiconClient\Tests;

use A21\LexiconClient\Files\LexiconFileWriter;
use A21\LexiconClient\Files\PullStateStore;
use Orchestra\Testbench\TestCase;

class LexiconFileWriterTest extends TestCase
{
    public function test_daily_pull_skips_when_hash_unchanged_even_if_disk_differs(): void
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
                'content' => ['artwork' => ['test_sync' => 'test sync']],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
        ], allowWithoutState: false);

        $this->assertSame([], $outcome['written']);
        $this->assertStringContainsString("'old'", (string) file_get_contents($path));
    }

    public function test_area_scope_writes_when_local_lags_even_if_hash_unchanged(): void
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
                'content' => ['artwork' => ['test_sync' => 'test sync']],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
        ], allowWithoutState: true);

        $this->assertSame([$path], $outcome['written']);
        $parsed = include $path;
        $this->assertSame('test sync', $parsed['artwork']['test_sync']);
    }

    public function test_hash_change_writes_only_that_file_on_daily_pull(): void
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
                'content' => ['other' => 'content'],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
        ]);

        $this->assertSame([$base.'/en/domains/artworks.php'], $outcome['written']);
        $this->assertSame(1, $outcome['skipped']);
    }
}
