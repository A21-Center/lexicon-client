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
        ]);

        $this->assertCount(1, $written);
        $this->assertFileExists($base.'/fr/catalog.json');
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
        ]);

        $this->assertSame([], $written);
    }
}
