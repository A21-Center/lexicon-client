<?php

namespace A21\LexiconClient\Tests\Import;

use A21\LexiconClient\Import\AreaCodeResolver;
use A21\LexiconClient\Import\JsonTranslationParser;
use A21\LexiconClient\Import\PhpTranslationParser;
use A21\LexiconClient\Import\TranslationFileScanner;
use PHPUnit\Framework\TestCase;

class ImportHelpersTest extends TestCase
{
    public function test_area_code_from_relative_path(): void
    {
        $resolver = new AreaCodeResolver();

        $this->assertSame('common.fields', $resolver->fromRelativePath('common/fields.php'));
        $this->assertSame('domains.artworks', $resolver->fromRelativePath('domains/artworks.php'));
        $this->assertSame('settings', $resolver->fromRelativePath('settings.php'));
        $this->assertSame('static_pages.about', $resolver->fromRelativePath('static pages/about.php'));
    }

    public function test_php_parser_reads_nested_array(): void
    {
        $path = sys_get_temp_dir().'/lexicon-import-test-'.uniqid('', true).'.php';
        file_put_contents($path, "<?php\n\nreturn ['title' => 'Artwork', 'actions' => ['create' => 'Create']];\n");

        $parsed = (new PhpTranslationParser())->parse($path);
        unlink($path);

        $this->assertSame('Artwork', $parsed['title']);
        $this->assertSame('Create', $parsed['actions']['create']);
    }

    public function test_json_parser_reads_object(): void
    {
        $path = sys_get_temp_dir().'/lexicon-import-test-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(['save' => 'Save', 'profile' => ['title' => 'Profile']], JSON_THROW_ON_ERROR));

        $parsed = (new JsonTranslationParser())->parse($path);
        unlink($path);

        $this->assertSame('Save', $parsed['save']);
        $this->assertSame('Profile', $parsed['profile']['title']);
    }

    public function test_scanner_auto_discovers_locales_and_areas(): void
    {
        $root = sys_get_temp_dir().'/lexicon-lang-'.uniqid('', true);
        mkdir($root.'/en/domains', 0777, true);
        mkdir($root.'/fr/domains', 0777, true);
        file_put_contents($root.'/en/domains/artworks.php', "<?php\nreturn ['title' => 'Artwork'];\n");
        file_put_contents($root.'/fr/domains/artworks.php', "<?php\nreturn ['title' => 'Oeuvre'];\n");

        $files = (new TranslationFileScanner())->scan($root, ['php']);

        $this->assertCount(2, $files);
        $this->assertSame('domains.artworks', $files[0]['area_code']);
        $this->assertSame('domains/artworks.php', $files[0]['relative_path']);

        array_map('unlink', [$root.'/en/domains/artworks.php', $root.'/fr/domains/artworks.php']);
        rmdir($root.'/en/domains');
        rmdir($root.'/fr/domains');
        rmdir($root.'/en');
        rmdir($root.'/fr');
        rmdir($root);
    }
}
