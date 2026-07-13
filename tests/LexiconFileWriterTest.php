<?php

namespace A21\LexiconClient\Tests;

use A21\LexiconClient\Files\LexiconFileWriter;
use A21\LexiconClient\Files\PullStateStore;
use Orchestra\Testbench\TestCase;

class LexiconFileWriterTest extends TestCase
{
    public function test_hash_match_skips_even_if_disk_differs(): void
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
        ]);

        $this->assertSame([], $outcome['written']);
        $this->assertSame(1, $outcome['skipped']);
        $this->assertStringContainsString("'old'", (string) file_get_contents($path));
    }

    public function test_hash_change_writes_only_that_file(): void
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
        $parsed = include $base.'/en/domains/artworks.php';
        $this->assertSame('test sync', $parsed['artwork']['test_sync']);
        $this->assertStringContainsString("'x'", (string) file_get_contents($base.'/en/common/alerts.php'));
    }

    public function test_without_state_skips_mass_write_unless_allowed(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        file_put_contents($base.'/en/domains/artworks.php', "<?php

return ['artwork' => ['a' => '1']];
");

        $state = new PullStateStore($base.'/pull-state.json');
        $writer = new LexiconFileWriter(pullState: $state);

        $file = [
            'language' => 'en',
            'area' => 'domains.artworks',
            'relative_path' => 'domains/artworks.php',
            'hash' => 'hash-artworks-1',
            'content' => ['artwork' => ['test_sync' => 'test sync']],
        ];
        $config = [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
        ];

        $blocked = $writer->write([$file], $config, allowWithoutState: false);

        $this->assertSame([], $blocked['written']);
        $this->assertSame(1, $blocked['skipped']);
        $this->assertSame('hash-artworks-1', $state->hashes()['en/domains/artworks.php'] ?? null);
        $this->assertStringContainsString("'a'", (string) file_get_contents($base.'/en/domains/artworks.php'));

        // Fresh empty state so --full path can write (disk differs from Lexicon).
        $base2 = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base2.'/en/domains', 0777, true);
        file_put_contents($base2.'/en/domains/artworks.php', "<?php

return ['artwork' => ['a' => '1']];
");
        $state2 = new PullStateStore($base2.'/pull-state.json');
        $writer2 = new LexiconFileWriter(pullState: $state2);
        $config2 = $config;
        $config2['base_path'] = $base2;

        $allowed = $writer2->write([$file], $config2, allowWithoutState: true);

        $this->assertSame([$base2.'/en/domains/artworks.php'], $allowed['written']);
    }


    public function test_partial_state_seeds_unknown_files_without_writing(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        @mkdir($base.'/en/common', 0777, true);
        file_put_contents($base.'/en/domains/artworks.php', "<?php\n\nreturn ['artwork' => ['a' => '1']];\n");
        file_put_contents($base.'/en/common/alerts.php', "<?php\n\nreturn ['x' => 'local'];\n");

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
            [
                'language' => 'en',
                'area' => 'common.alerts',
                'relative_path' => 'common/alerts.php',
                'hash' => 'hash-alerts-1',
                'content' => ['other' => 'from lexicon'],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
        ], allowWithoutState: false);

        $this->assertSame([], $outcome['written']);
        $this->assertSame(2, $outcome['skipped']);
        $hashes = $state->hashes();
        $this->assertSame('hash-artworks-1', $hashes['en/domains/artworks.php'] ?? null);
        $this->assertSame('hash-alerts-1', $hashes['en/common/alerts.php'] ?? null);
        $this->assertStringContainsString("'a'", (string) file_get_contents($base.'/en/domains/artworks.php'));
        $this->assertStringContainsString("'x'", (string) file_get_contents($base.'/en/common/alerts.php'));

        // Later Lexicon change on seeded alerts → write only alerts.
        $outcome2 = $writer->write([
            [
                'language' => 'en',
                'area' => 'domains.artworks',
                'relative_path' => 'domains/artworks.php',
                'hash' => 'hash-artworks-1',
                'content' => ['artwork' => ['test_sync' => 'test sync']],
            ],
            [
                'language' => 'en',
                'area' => 'common.alerts',
                'relative_path' => 'common/alerts.php',
                'hash' => 'hash-alerts-2',
                'content' => ['other' => 'updated'],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
        ], allowWithoutState: false);

        $this->assertSame([$base.'/en/common/alerts.php'], $outcome2['written']);
        $this->assertSame(1, $outcome2['skipped']);
        $parsed = include $base.'/en/common/alerts.php';
        $this->assertSame('updated', $parsed['other']);
    }
}
