<?php

namespace A21\LexiconClient\Tests;

use A21\LexiconClient\Extraction\ExtractionRunner;
use A21\LexiconClient\Extraction\ExtractorRegistry;
use A21\LexiconClient\Extraction\ExtractorRegistryFactory;
use A21\LexiconClient\Extraction\Extractors\BladeExtractor;
use A21\LexiconClient\Extraction\Extractors\DatabaseExtractor;
use A21\LexiconClient\Extraction\Extractors\FilesExtractor;
use A21\LexiconClient\Manifest\LexiconManifestReader;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;
use RuntimeException;

class ExtractionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\A21\LexiconClient\LexiconClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function test_registry_registers_and_resolves_types(): void
    {
        $registry = ExtractorRegistryFactory::default();

        $this->assertTrue($registry->has('files'));
        $this->assertTrue($registry->has('database'));
        $this->assertTrue($registry->has('content'));
        $this->assertTrue($registry->has('blade'));
        $this->assertInstanceOf(FilesExtractor::class, $registry->get('files'));
        $this->assertContains('database', $registry->types());
    }

    public function test_runner_filters_by_group(): void
    {
        $registry = new ExtractorRegistry();
        $registry->register(new BladeExtractor());

        $dir = $this->makeTempDir();
        file_put_contents($dir.'/welcome.blade.php', "<h1>{{ __('Welcome home') }}</h1>");

        $runner = new ExtractionRunner($registry);

        $definitions = [
            ['group' => 'emails', 'type' => 'blade', 'paths' => [$dir], 'area' => 'emails'],
            ['group' => 'other', 'type' => 'blade', 'paths' => [$dir], 'area' => 'other'],
        ];

        $all = $runner->run($definitions);
        $this->assertCount(2, $all);

        $filtered = $runner->run($definitions, ['groups' => ['emails']]);
        $this->assertCount(1, $filtered);
        $this->assertSame('emails', $filtered[0]->areaCode);
    }

    public function test_runner_throws_for_unknown_type(): void
    {
        $runner = new ExtractionRunner(new ExtractorRegistry());

        $this->expectException(RuntimeException::class);
        $runner->run([['group' => 'x', 'type' => 'nope']]);
    }

    public function test_files_extractor_flattens_php_and_json(): void
    {
        $dir = $this->makeTempDir();
        mkdir($dir.'/en', 0777, true);
        file_put_contents($dir.'/en/auth.php', "<?php\n\nreturn ['failed' => 'These credentials do not match.', 'nested' => ['title' => 'Login']];\n");
        file_put_contents($dir.'/en/messages.json', json_encode(['welcome' => 'Welcome']));

        $entries = (new FilesExtractor())->extract([
            'base_path' => $dir,
            'source_language' => 'en',
            'application' => 'studio',
            'module' => 'interface',
        ]);

        $fields = array_map(fn ($e) => $e->fieldName, $entries);
        sort($fields);

        $this->assertSame(['failed', 'nested.title', 'welcome'], $fields);

        foreach ($entries as $entry) {
            $this->assertSame('interface', $entry->layer);
            $this->assertSame('translation_file', $entry->entityType);
        }
    }

    public function test_blade_extractor_extracts_helper_strings(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents(
            $dir.'/welcome.blade.php',
            "<h1>{{ __('Welcome') }}</h1><p>@lang('Sign in now')</p>{{ trans('Welcome') }}"
        );

        $entries = (new BladeExtractor())->extract([
            'paths' => [$dir],
            'application' => 'studio',
            'module' => 'emails',
            'area' => 'emails',
        ]);

        $texts = array_map(fn ($e) => $e->sourceText, $entries);

        $this->assertContains('Welcome', $texts);
        $this->assertContains('Sign in now', $texts);
        // Deduplicated per file+string.
        $this->assertCount(2, $entries);
        $this->assertSame('template', $entries[0]->layer);
    }

    public function test_database_extractor_reads_rows(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
        });

        DB::table('categories')->insert([
            ['code' => 'drawing', 'name' => 'Drawing', 'description' => 'Sketches'],
            ['code' => 'painting', 'name' => 'Painting', 'description' => null],
        ]);

        $entries = (new DatabaseExtractor())->extract([
            'table' => 'categories',
            'entity_type' => 'category',
            'id_column' => 'id',
            'code_column' => 'code',
            'fields' => ['name', 'description'],
            'area' => 'categories',
            'application' => 'hub',
            'module' => 'cs',
            'source_url' => '/crm/categories/{id}',
        ]);

        // 2 rows, 3 non-empty field values (painting.description is null).
        $this->assertCount(3, $entries);
        $this->assertSame('database', $entries[0]->layer);
        $this->assertSame('category', $entries[0]->entityType);
        $this->assertSame('drawing', $entries[0]->metadata['code']);
        $this->assertSame('/crm/categories/1', $entries[0]->sourceUrl);
    }

    public function test_manifest_reader_exposes_extractors(): void
    {
        $manifestPath = $this->makeTempDir().'/lexicon.json';
        file_put_contents($manifestPath, json_encode([
            'client' => 'studio-backend',
            'project' => 'studio',
            'extractors' => [
                ['group' => 'emails', 'type' => 'blade', 'paths' => ['resources/views/emails']],
            ],
        ]));

        config([
            'lexicon.manifest' => $manifestPath,
            'lexicon.api_url' => 'https://lexicon.test',
            'lexicon.secret' => 'lex_sk_live_testsecret12',
        ]);

        $merged = (new LexiconManifestReader())->mergedConfig();

        $this->assertCount(1, $merged['extractors']);
        $this->assertSame('blade', $merged['extractors'][0]['type']);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/lexicon-extract-'.uniqid('', true);
        mkdir($dir, 0777, true);

        return $dir;
    }
}
