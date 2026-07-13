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

        $written = $writer->write([
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

        $this->assertCount(1, $written);
        $this->assertFileExists($base.'/fr/catalog.json');
    }

    public function test_it_updates_existing_php_lang_files(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        file_put_contents($base.'/en/domains/artworks.php', "<?php\n\nreturn [\n    'artwork' => [\n        'draft' => ['label' => 'Draft'],\n    ],\n];\n");

        $writer = new LexiconFileWriter();
        $written = $writer->write([
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
        ], false, true);

        $this->assertSame([$base.'/en/domains/artworks.php'], $written);
        $parsed = include $base.'/en/domains/artworks.php';
        $this->assertSame('test sync', $parsed['artwork']['test_sync']);
        $this->assertSame('Draft', $parsed['artwork']['draft']['label']);
    }

    public function test_it_skips_unchanged_files_when_hash_matches(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/fr', 0777, true);
        $content = json_encode(['ui' => ['label' => 'Art']], JSON_UNESCAPED_UNICODE);
        file_put_contents($base.'/fr/catalog.json', $content);

        $writer = new LexiconFileWriter();
        $written = $writer->write([
            [
                'language' => 'fr',
                'area' => 'catalog',
                'hash' => hash('sha256', (string) $content),
                'content' => ['ui' => ['label' => 'Art']],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{area}.json',
            'format' => 'nested_json',
        ]);

        $this->assertSame([], $written);
    }
}
