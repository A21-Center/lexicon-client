<?php

namespace A21\LexiconClient\Tests;

use A21\LexiconClient\Files\LexiconFileWriter;
use A21\LexiconClient\Files\PhpArrayFilePatcher;
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

    public function test_area_scope_adds_missing_key_without_dropping_existing(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        $path = $base.'/en/domains/artworks.php';
        $original = <<<'PHP'
<?php

return [
    'artwork' => [
        // keep this comment
        'old' => 'value',
    ],
];
PHP;
        file_put_contents($path, $original);

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
            'merge' => 'add_missing',
        ], allowWithoutState: true);

        $this->assertSame([$path], $outcome['written']);
        $parsed = include $path;
        $this->assertSame('value', $parsed['artwork']['old']);
        $this->assertSame('test sync', $parsed['artwork']['test_sync']);
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('// keep this comment', $body);
        $this->assertStringContainsString("'old' => 'value'", $body);
    }

    public function test_add_missing_does_not_overwrite_local_values(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        $path = $base.'/en/domains/artworks.php';
        file_put_contents($path, "<?php\n\nreturn [\n    'artwork' => [\n        'label' => 'Local English',\n    ],\n];\n");

        $state = new PullStateStore($base.'/pull-state.json');
        $state->save(['en/domains/artworks.php' => 'hash-1']);

        $writer = new LexiconFileWriter(pullState: $state);
        $outcome = $writer->write([
            [
                'language' => 'en',
                'area' => 'domains.artworks',
                'relative_path' => 'domains/artworks.php',
                'hash' => 'hash-2',
                'content' => ['artwork' => ['label' => 'تمت الموافقة']],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
            'merge' => 'add_missing',
        ], allowWithoutState: true);

        $this->assertSame([], $outcome['written']);
        $parsed = include $path;
        $this->assertSame('Local English', $parsed['artwork']['label']);
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
        $parsed = include $base.'/en/domains/artworks.php';
        $this->assertSame('1', $parsed['artwork']['a']);
        $this->assertSame('test sync', $parsed['artwork']['test_sync']);
    }

    public function test_patcher_preserves_unrelated_lines(): void
    {
        $source = <<<'PHP'
<?php

return [
    'artwork' => [
        'draft' => [
            'label' => 'Draft',
        ],
    ],
    'specifications' => [
        'finishes' => [
            'helper' => 'You may select multiple options.',
            'label' => 'Finishes',
        ],
    ],
];
PHP;

        $patcher = new PhpArrayFilePatcher();
        $patched = $patcher->injectMissingLeaves($source, [
            'artwork.test_sync' => 'test sync',
        ]);

        $this->assertNotNull($patched);
        $this->assertStringContainsString("'test_sync' => 'test sync'", (string) $patched);
        $this->assertStringContainsString("'helper' => 'You may select multiple options.'", (string) $patched);
        $this->assertStringContainsString("'label' => 'Finishes'", (string) $patched);
        // Must nest into existing artwork — not append a duplicate top-level key.
        $this->assertSame(1, substr_count((string) $patched, "'artwork'"));
        $this->assertStringContainsString("'draft'", (string) $patched);

        $tmp = tempnam(sys_get_temp_dir(), 'lexphp');
        file_put_contents($tmp, (string) $patched);
        /** @var array<string, mixed> $parsed */
        $parsed = include $tmp;
        @unlink($tmp);

        $this->assertSame('test sync', $parsed['artwork']['test_sync']);
        $this->assertSame('Draft', $parsed['artwork']['draft']['label']);
        $this->assertSame('Finishes', $parsed['specifications']['finishes']['label']);
    }

    public function test_add_missing_skips_new_top_level_branches(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        $path = $base.'/en/domains/artworks.php';
        file_put_contents($path, <<<'PHP'
<?php

return [
    'artwork' => [
        'draft' => [
            'label' => 'Draft',
        ],
    ],
];
PHP);

        $state = new PullStateStore($base.'/pull-state.json');
        $writer = new LexiconFileWriter(pullState: $state);
        $outcome = $writer->write([
            [
                'language' => 'en',
                'area' => 'domains.artworks',
                'relative_path' => 'domains/artworks.php',
                'hash' => 'hash-new',
                'content' => [
                    'artwork' => [
                        'draft' => ['label' => 'Draft'],
                        'test_sync' => 'test sync',
                    ],
                    'filters' => [
                        'panel' => ['title' => 'خيارات الفلترة'],
                    ],
                ],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
            'merge' => 'add_missing',
        ], allowWithoutState: true);

        $this->assertSame([$path], $outcome['written']);
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString("'test_sync' => 'test sync'", $body);
        $this->assertStringNotContainsString('filters', $body);
        $this->assertStringNotContainsString('خيارات', $body);
    }

    public function test_add_missing_skips_empty_values(): void
    {
        $base = sys_get_temp_dir().'/lexicon-client-test-'.uniqid();
        @mkdir($base.'/en/domains', 0777, true);
        $path = $base.'/en/domains/artworks.php';
        file_put_contents($path, <<<'PHP'
<?php

return [
    'artwork' => [
        'draft' => [
            'label' => 'Draft',
        ],
    ],
    'editor' => [
        'main_image' => [
            'title' => 'Main image',
        ],
        'details' => [
            'artwork_title' => [
                'label' => 'Title',
            ],
        ],
    ],
];
PHP);

        $state = new PullStateStore($base.'/pull-state.json');
        $writer = new LexiconFileWriter(pullState: $state);
        $outcome = $writer->write([
            [
                'language' => 'en',
                'area' => 'domains.artworks',
                'relative_path' => 'domains/artworks.php',
                'hash' => 'hash-empty',
                'content' => [
                    'artwork' => [
                        'draft' => ['label' => 'Draft'],
                        'test_sync' => 'test sync',
                    ],
                    'editor' => [
                        'main_image' => [
                            'title' => 'Main image',
                            'alt' => '',
                        ],
                        'details' => [
                            'artwork_title' => [
                                'label' => 'Title',
                                'placeholder' => '',
                            ],
                        ],
                    ],
                ],
            ],
        ], [
            'base_path' => $base,
            'pattern' => '{locale}/{relative_path}',
            'format' => 'php',
            'merge' => 'add_missing',
        ], allowWithoutState: true);

        $this->assertSame([$path], $outcome['written']);
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString("'test_sync' => 'test sync'", $body);
        $this->assertStringNotContainsString("'alt'", $body);
        $this->assertStringNotContainsString('placeholder', $body);
    }
}
