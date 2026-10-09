<?php

namespace A21\LexiconClient\Tests;

use A21\LexiconClient\Support\KeyUsageScanner;
use Orchestra\Testbench\TestCase;

class KeyUsageScannerTest extends TestCase
{
    public function test_scan_finds_exact_literals_and_ignores_dynamics(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir.'/welcome.blade.php', <<<'BLADE'
<h1>{{ __('age_artist') }}</h1>
<p>{{ __('artist.'.$slug) }}</p>
<span>@lang('profile.introduction')</span>
BLADE);
        file_put_contents($dir.'/app.js', "const label = t('checkout.pay');\nconst bad = t('dynamic.' . id);\n");

        $scanner = new KeyUsageScanner();
        $found = $scanner->scan([$dir]);

        $this->assertArrayHasKey('age_artist', $found);
        $this->assertArrayHasKey('profile.introduction', $found);
        $this->assertArrayHasKey('checkout.pay', $found);
        $this->assertArrayNotHasKey('artist.', $found);
        $this->assertFalse(collect(array_keys($found))->contains(fn ($key) => str_contains($key, '$')));
    }

    public function test_build_results_marks_used_and_unused(): void
    {
        $scanner = new KeyUsageScanner();
        $results = $scanner->buildResults(
            ['age_artist', 'missing.key'],
            ['age_artist' => [['path' => 'resources/views/x.blade.php', 'line' => 3]]],
        );

        $this->assertSame('used', $results[0]['status']);
        $this->assertSame('unused', $results[1]['status']);
        $this->assertSame('resources/views/x.blade.php', $results[0]['evidence'][0]['path']);
    }

    public function test_collect_keys_from_php_and_json(): void
    {
        $dir = $this->makeTempDir();
        file_put_contents($dir.'/en.php', "<?php\nreturn ['age_artist' => 'Age', 'nested' => ['title' => 'Title']];\n");
        file_put_contents($dir.'/en.json', json_encode(['flat.key' => 'Hello', 'group' => ['item' => 'Item']], JSON_THROW_ON_ERROR));

        $scanner = new KeyUsageScanner();
        $keys = $scanner->collectKeysFromTranslationFiles([
            $dir.'/en.php',
            $dir.'/en.json',
        ]);

        $this->assertSame(['age_artist', 'flat.key', 'group.item', 'nested.title'], $keys);
    }

    public function test_normalize_and_message_helper_matching(): void
    {
        $scanner = new KeyUsageScanner();
        $this->assertSame(
            'gallery.account.new_billing_address.intro',
            $scanner->normalizeKeyForLexicon('emails/gallery/account/new-billing-address.intro'),
        );
        $this->assertSame(
            'studio.artwork.unauthorized',
            $scanner->normalizeKeyForLexicon('api/studio/artwork.unauthorized'),
        );

        $dir = $this->makeTempDir();
        file_put_contents($dir.'/ArtworkMessage.php', <<<'PHP'
<?php
class ArtworkMessage {
    public static function get(string $key, array $replace = []): string {
        return (string) __('api/studio/artwork.'.$key, $replace);
    }
}
PHP);
        file_put_contents($dir.'/Controller.php', <<<'PHP'
<?php
$message = ArtworkMessage::get('unauthorized');
$title = __('emails/gallery/account/new-billing-address.intro');
PHP);

        $found = $scanner->scan([$dir]);
        $found = $scanner->expandMessageHelperUsage([$dir], $found);
        $results = $scanner->buildResults(
            ['studio.artwork.unauthorized', 'gallery.account.new_billing_address.intro', 'missing.key'],
            $found,
        );

        $byKey = collect($results)->keyBy('full_key');
        $this->assertSame('used', $byKey['studio.artwork.unauthorized']['status']);
        $this->assertSame('used', $byKey['gallery.account.new_billing_address.intro']['status']);
        $this->assertSame('unused', $byKey['missing.key']['status']);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/lexicon-usage-'.uniqid('', true);
        mkdir($dir);

        return $dir;
    }
}
