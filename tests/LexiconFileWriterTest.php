<?php

namespace A21\LexiconClient\Tests;

use A21\LexiconClient\Files\LexiconFileWriter;
use Orchestra\Testbench\TestCase;

class LexiconFileWriterTest extends TestCase
{
    public function test_it_writes_nested_json_files_using_pattern(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        $writer = new LexiconFileWriter();

        $outcome = $writer->write([
            [
                'language' => 'fr',
                'area' => 'catalog',
                'hash' => hash('sha256', '{"ui":{"label":"Art"}}'),
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

    public function test_it_updates_existing_php_lang_files(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        file_put_contents($base.'/en/domains/artworks.php', "<?php\n\nreturn [\n    'artwork' => [\n        'draft' => ['label' => 'Draft'],\n    ],\n];\n");

        $writer = new LexiconFileWriter();
        $outcome = $writer->write([
            [
                'language' => 'en',
                'area' => 'domains.artworks',
                'relative_path' => 'domains/artworks.php',
                'content' => [
                    'artwork' => [
                        'draft' => ['label' => 'Draft'],
                        'test_sync' => 'test sync',
                    ],
                ],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
        ]);

        $this->assertSame([$base.'/en/domains/artworks.php'], $outcome['written']);
        $this->assertSame(0, $outcome['skipped']);
        $parsed = include $base.'/en/domains/artworks.php';
        $this->assertSame('test sync', $parsed['artwork']['test_sync']);
        $this->assertSame('Draft', $parsed['artwork']['draft']['label']);
    }

    public function test_it_skips_unchanged_php_files_with_different_key_order(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        file_put_contents(
            $base.'/en/domains/artworks.php',
            "<?php\n\nreturn [\n    'artwork' => [\n        'test_sync' => 'test sync',\n        'draft' => ['label' => 'Draft'],\n    ],\n];\n",
        );

        $writer = new LexiconFileWriter();
        $outcome = $writer->write([
            [
                'language' => 'en',
                'area' => 'domains.artworks',
                'relative_path' => 'domains/artworks.php',
                'content' => [
                    'artwork' => [
                        'draft' => ['label' => 'Draft'],
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
    }

    public function test_it_skips_unchanged_files_when_content_matches(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/fr', 0777, true);
        $payload = ['ui' => ['label' => 'Art']];
        file_put_contents(
            $base.'/fr/catalog.json',
            (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );

        $writer = new LexiconFileWriter();
        $outcome = $writer->write([
            [
                'language' => 'fr',
                'area' => 'catalog',
                'content' => $payload,
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{area}.json',
            'format' => 'nested_json',
        ]);

        $this->assertSame([], $outcome['written']);
        $this->assertSame(1, $outcome['skipped']);
    }

    public function test_force_overwrites_identical_content(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        $path = $base.'/en/domains/artworks.php';
        file_put_contents($path, "<?php\n\nreturn ['artwork' => ['draft' => ['label' => 'Draft']]];\n");
        $mtimeBefore = filemtime($path);

        sleep(1);

        $writer = new LexiconFileWriter();
        $outcome = $writer->write([
            [
                'language' => 'en',
                'area' => 'domains.artworks',
                'relative_path' => 'domains/artworks.php',
                'content' => [
                    'artwork' => [
                        'draft' => ['label' => 'Draft'],
                    ],
                ],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
        ], false, true);

        $this->assertSame([$path], $outcome['written']);
        $this->assertSame(0, $outcome['skipped']);
        $this->assertGreaterThan($mtimeBefore, (int) filemtime($path));
    }
}
