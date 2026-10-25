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

    public function test_concatenated_home_prefix_matches_lexicon_keys_case_insensitively(): void
    {
        $scanner = new KeyUsageScanner();
        $this->assertSame(
            'static_pages.home.1_Slide.title',
            $scanner->normalizeKeyForLexicon('static pages/home.1_Slide.title'),
        );

        $dir = $this->makeTempDir();
        file_put_contents($dir.'/accueil.blade.php', <<<'BLADE'
@php
    $homeText = static function (string $key, string $fallback = ''): string {
        $value = filled_trans('static pages/home.'.$key);
        return $value !== '' ? $value : $fallback;
    };
    $title = $homeText('1_Slide.title');
    $unusedSlide = '2_Slide.title';
    $hashtagKeys = [
        'audacity',
        'solidarity',
    ];
    foreach ($hashtagKeys as $hashtagKey) {
        $tag = $homeText('hashtags.items.'.$hashtagKey);
    }
@endphp
BLADE);

        $found = $scanner->expandConcatenatedPrefixUsage([$dir], $scanner->scan([$dir]));
        $results = $scanner->buildResults([
            'static_pages.home.1_slide.title',
            'static_pages.home.2_Slide.title',
            'static_pages.home.hashtags.items.audacity',
            'static_pages.home.hashtags.items.solidarity',
            'static_pages.home.hashtags.items.efficiency',
        ], $found);

        $byKey = collect($results)->keyBy('full_key');
        $this->assertSame('used', $byKey['static_pages.home.1_slide.title']['status']);
        $this->assertSame('unused', $byKey['static_pages.home.2_Slide.title']['status']);
        $this->assertSame('used', $byKey['static_pages.home.hashtags.items.audacity']['status']);
        $this->assertSame('used', $byKey['static_pages.home.hashtags.items.solidarity']['status']);
        $this->assertSame('unused', $byKey['static_pages.home.hashtags.items.efficiency']['status']);
    }

    public function test_open_prefix_marks_dynamic_js_and_php_keys_used(): void
    {
        $dir = $this->makeTempDir();
        mkdir($dir.'/views');
        mkdir($dir.'/js');
        file_put_contents($dir.'/views/filters.blade.php', <<<'PHP'
<?php
__('domains.artwork.filters.' . $key);
PHP);
        file_put_contents($dir.'/js/techniques.js', <<<'JS'
const translated = t(`cs.techniques.${slug}`, {}, '');
JS);

        $scanner = new KeyUsageScanner();
        $found = $scanner->expandConcatenatedPrefixUsage([$dir], []);
        $results = $scanner->buildResults([
            'domains.artwork.filters.price',
            'cs.techniques.oil',
            'cs.materials.canvas',
        ], $found);

        $byKey = collect($results)->keyBy('full_key');
        $this->assertSame('used', $byKey['domains.artwork.filters.price']['status']);
        $this->assertSame('used', $byKey['cs.techniques.oil']['status']);
        $this->assertSame('unused', $byKey['cs.materials.canvas']['status']);
    }

    public function test_computed_full_key_variables_resolve_to_catalog_keys(): void
    {
        $dir = $this->makeTempDir();
        mkdir($dir.'/views');
        mkdir($dir.'/js');
        file_put_contents($dir.'/views/settings.blade.php', <<<'PHP'
<?php
$settingsTitleKeys = [
    'settings.profile.index' => 'root/settings.personal_settings.header.title',
];
$settingsPageTitle = __($settingsTitleKeys[$settingsRouteName] ?? 'root/settings.fallback.title');
PHP);
        file_put_contents($dir.'/views/sitemap.php', <<<'PHP'
<?php
class Sitemap
{
    private function staticListing(string $id, string $path, string $titleKey, string $descriptionKey): void
    {
        $title = __($titleKey);
        $description = __($descriptionKey);
    }

    public function pages(): void
    {
        $this->staticListing('explore-artworks', '/explore/artworks', 'domains/artwork.explore.page_title', 'domains/artwork.explore.seo_description');
    }
}
PHP);
        file_put_contents($dir.'/views/langkey.php', <<<'PHP'
<?php
function base_language_key(string $locale): string
{
    return 'common/ui.language.'.($locale === 'fr' ? 'full' : 'short').'.'.$locale;
}
$labelKey = base_language_key($localeCode);
$localeLabel = __($labelKey);
PHP);
        file_put_contents($dir.'/js/dynamic.js', <<<'JS'
function t(key, replacements = {}, fallback = '') {
  return i18n.t(key, replacements, fallback);
}
const categoryKey = `cs.categories.${slug}`;
t(categoryKey, {}, '');
const SALE = { blocked: { labelKey: 'domains.sales.order_status.blocked.label', fallback: 'Blocked' } };
t(phase.labelKey, {}, phase.fallback);
function applyOptionsToSelect(selectElement, labelKey, fallbackLabel) {
  t(labelKey, {}, fallbackLabel);
}
applyOptionsToSelect(el, 'domains.dashboard.min_label', 'Min');
t('known.only');
function classificationGroupTitleKeys() {
  const keys = [];
  keys.push(`cs.attribute_groups.${area}`);
  keys.push('common.ui.general_label.other');
  return keys;
}
function firstTranslatedKey(keys) {
  for (const key of keys) {
    t(key, {}, '');
  }
}
firstTranslatedKey(classificationGroupTitleKeys());
JS);

        $scanner = new KeyUsageScanner();
        $found = $scanner->expandComputedKeyUsage([$dir], $scanner->scan([$dir]));
        $results = $scanner->buildResults([
            'root.settings.personal_settings.header.title',
            'root.settings.fallback.title',
            'domains.artwork.explore.page_title',
            'domains.artwork.explore.seo_description',
            'common.ui.language.full.french',
            'cs.categories.oil',
            'domains.sales.order_status.blocked.label',
            'domains.dashboard.min_label',
            'cs.attribute_groups.technique',
            'common.ui.general_label.other',
            'known.only',
            'not.used.key',
        ], $found);

        $byKey = collect($results)->keyBy('full_key');
        $this->assertSame('used', $byKey['root.settings.personal_settings.header.title']['status']);
        $this->assertSame('used', $byKey['root.settings.fallback.title']['status']);
        $this->assertSame('used', $byKey['domains.artwork.explore.page_title']['status']);
        $this->assertSame('used', $byKey['domains.artwork.explore.seo_description']['status']);
        $this->assertSame('used', $byKey['common.ui.language.full.french']['status']);
        $this->assertSame('used', $byKey['cs.categories.oil']['status']);
        $this->assertSame('used', $byKey['domains.sales.order_status.blocked.label']['status']);
        $this->assertSame('used', $byKey['domains.dashboard.min_label']['status']);
        $this->assertSame('used', $byKey['cs.attribute_groups.technique']['status']);
        $this->assertSame('used', $byKey['common.ui.general_label.other']['status']);
        $this->assertSame('used', $byKey['known.only']['status']);
        $this->assertSame('unused', $byKey['not.used.key']['status']);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/lexicon-usage-'.uniqid('', true);
        mkdir($dir);

        return $dir;
    }
}
