<?php

namespace A21\LexiconClient\Tests;

use A21\LexiconClient\Rewrite\KeyRewriteService;
use Orchestra\Testbench\TestCase;

class KeyRewriteServiceTest extends TestCase
{
    public function test_rewrites_lang_php_and_json_entries(): void
    {
        $base = $this->makeTempDir();
        $lang = $base.'/lang/fr';
        mkdir($lang, 0777, true);

        file_put_contents($lang.'/artwork.php', "<?php\n\nreturn ['artwork_name' => 'nom', 'keep' => 'x'];\n");
        file_put_contents($lang.'/messages.json', json_encode([
            'artwork_name' => 'nom',
            'nested' => ['title' => 'T'],
        ], JSON_THROW_ON_ERROR));

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            ['artwork.artwork_name' => 'artwork.name_artwork'],
            [],
            [$base.'/lang'],
            dryRun: false,
            doLang: true,
            doCode: false,
        );

        $this->assertNotEmpty($summary['lang']);

        $php = include $lang.'/artwork.php';
        $this->assertArrayHasKey('name_artwork', $php);
        $this->assertArrayNotHasKey('artwork_name', $php);
        $this->assertSame('nom', $php['name_artwork']);
        $this->assertSame('x', $php['keep']);
    }

    public function test_moves_lang_entry_across_area_files(): void
    {
        $base = $this->makeTempDir();
        $fr = $base.'/lang/fr';
        mkdir($fr.'/common', 0777, true);
        mkdir($fr.'/domains', 0777, true);

        file_put_contents($fr.'/common/ui.php', "<?php\n\nreturn ['dimensions' => ['full' => ['depth' => 'Profondeur']]];\n");
        file_put_contents($fr.'/domains/artworks.php', "<?php\n\nreturn ['editor' => ['collection' => ['helper' => 'Aide']]];\n");

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            [
                'common.ui.dimensions.full.depth' => 'domains.artworks.editor.collection.depth',
            ],
            [],
            [$base.'/lang'],
            dryRun: false,
            doLang: true,
            doCode: false,
        );

        $this->assertCount(2, $summary['lang']);

        $ui = include $fr.'/common/ui.php';
        $artworks = include $fr.'/domains/artworks.php';

        $this->assertTrue(! isset($ui['dimensions']['full']['depth']));
        $this->assertSame('Aide', $artworks['editor']['collection']['helper']);
        $this->assertSame('Profondeur', $artworks['editor']['collection']['depth']);
    }

    public function test_rewrites_lang_entry_in_all_locales(): void
    {
        $base = $this->makeTempDir();
        foreach (['en', 'fr', 'de', 'ar'] as $locale) {
            $dir = $base.'/lang/'.$locale.'/domains';
            mkdir($dir, 0777, true);
            file_put_contents(
                $dir.'/artworks.php',
                "<?php\n\nreturn ['editor' => ['details' => ['additional_images' => ['upload' => 'Upload ".$locale."', 'keep' => 'k']]]];\n"
            );
        }

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            [
                'domains.artworks.editor.details.additional_images.upload'
                    => 'domains.artworks.editor.details.description.helper',
            ],
            [],
            [$base.'/lang'],
            dryRun: false,
            doLang: true,
            doCode: false,
        );

        $this->assertCount(4, $summary['lang']);
        foreach (['en', 'fr', 'de', 'ar'] as $locale) {
            $data = include $base.'/lang/'.$locale.'/domains/artworks.php';
            $this->assertArrayNotHasKey('upload', $data['editor']['details']['additional_images'], $locale);
            $this->assertSame('Upload '.$locale, $data['editor']['details']['description']['helper'], $locale);
            $this->assertSame('k', $data['editor']['details']['additional_images']['keep'], $locale);
        }
    }

    public function test_rewrites_exact_code_literals_and_skips_dynamics(): void
    {
        $base = $this->makeTempDir();
        $views = $base.'/resources/views';
        mkdir($views, 0777, true);

        file_put_contents($views.'/card.blade.php', <<<'BLADE'
{{ __('artwork_name') }}
{{ __('artist.'.$slug) }}
@lang('profile.introduction')
BLADE);
        file_put_contents($base.'/resources/app.js', "t('artwork_name');\nt('dynamic.' + id);\n");

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            ['artwork_name' => 'name_artwork'],
            [$base.'/resources'],
            [],
            dryRun: false,
            doLang: false,
            doCode: true,
        );

        $this->assertNotEmpty($summary['code']);
        $blade = (string) file_get_contents($views.'/card.blade.php');
        $js = (string) file_get_contents($base.'/resources/app.js');

        $this->assertStringContainsString("__('name_artwork')", $blade);
        $this->assertStringNotContainsString("__('artwork_name')", $blade);
        $this->assertStringContainsString('__(\'artist.\'.$slug)', $blade);
        $this->assertStringContainsString("t('name_artwork')", $js);
        $this->assertStringContainsString("t('dynamic.' + id)", $js);
    }

    public function test_rewrites_public_js_t_literals(): void
    {
        $base = $this->makeTempDir();
        $jsDir = $base.'/public/js/pages';
        mkdir($jsDir, 0777, true);
        file_put_contents(
            $jsDir.'/career.js',
            "t('root.configuration.artistic_career.question');\nt('root/configuration.artistic_career.question');\n",
        );

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            [
                'root.configuration.artistic_career.question' => 'domains.sales.artistic_career.question',
            ],
            [$base.'/public/js'],
            [],
            dryRun: false,
            doLang: false,
            doCode: true,
        );

        $this->assertNotEmpty($summary['code']);
        $js = (string) file_get_contents($jsDir.'/career.js');
        $this->assertStringContainsString("t('domains.sales.artistic_career.question')", $js);
        $this->assertStringContainsString("t('domains/sales.artistic_career.question')", $js);
        $this->assertStringNotContainsString('root.configuration.artistic_career.question', $js);
        $this->assertStringNotContainsString('root/configuration.artistic_career.question', $js);
    }

    public function test_rewrites_gallery_t_with_fallback_arg_in_public_js(): void
    {
        $base = $this->makeTempDir();
        $jsDir = $base.'/public/js/pages';
        mkdir($jsDir, 0777, true);
        file_put_contents(
            $jsDir.'/settings.js',
            "const retryButton = create('button', { text: t('root.settings.client.load_errors.retry', {}, 'Retry') });\n",
        );

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            [
                'root.settings.client.load_errors.retry' => 'errors.general.client.load_errors.retry',
            ],
            [$base.'/public/js'],
            [],
            dryRun: false,
            doLang: false,
            doCode: true,
        );

        $this->assertNotEmpty($summary['code']);
        $js = (string) file_get_contents($jsDir.'/settings.js');
        $this->assertStringContainsString(
            "t('errors.general.client.load_errors.retry', {}, 'Retry')",
            $js,
        );
        $this->assertStringNotContainsString('root.settings.client.load_errors.retry', $js);
    }

    public function test_rewrites_blade_slash_and_js_dot_variants_together(): void
    {
        $base = $this->makeTempDir();
        $views = $base.'/resources/views';
        $jsDir = $base.'/public/js';
        mkdir($views, 0777, true);
        mkdir($jsDir, 0777, true);
        file_put_contents($views.'/painting.blade.php', "{{ __('common/ui.dimensions.full.height') }}\n");
        file_put_contents($jsDir.'/runtime.js', "t('common.ui.dimensions.full.height');\n");

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            [
                'common.ui.dimensions.full.height' => 'root.configuration.dimensions.full.height',
            ],
            [$base.'/resources', $base.'/public'],
            [],
            dryRun: false,
            doLang: false,
            doCode: true,
        );

        $this->assertCount(2, $summary['code']);
        $blade = (string) file_get_contents($views.'/painting.blade.php');
        $js = (string) file_get_contents($jsDir.'/runtime.js');
        $this->assertStringContainsString("__('root/configuration.dimensions.full.height')", $blade);
        $this->assertStringContainsString("t('root.configuration.dimensions.full.height')", $js);
        $this->assertStringNotContainsString('common/ui.dimensions.full.height', $blade);
        $this->assertStringNotContainsString('common.ui.dimensions.full.height', $js);
    }

    public function test_rewrites_laravel_hybrid_lang_keys_in_blade(): void
    {
        $base = $this->makeTempDir();
        $views = $base.'/resources/views';
        mkdir($views, 0777, true);
        file_put_contents($views.'/painting.blade.php', <<<'BLADE'
{{ __('domains/artworks.editor.collection.helper') }}
{{ __('common/ui.dimensions.full.depth') }}
BLADE);

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            [
                'domains.artworks.editor.collection.helper' => 'common.ui.dimensions.full.depth',
            ],
            [$base.'/resources'],
            [],
            dryRun: false,
            doLang: false,
            doCode: true,
        );

        $this->assertNotEmpty($summary['code']);
        $blade = (string) file_get_contents($views.'/painting.blade.php');
        $this->assertStringContainsString("__('common/ui.dimensions.full.depth')", $blade);
        $this->assertStringNotContainsString("__('domains/artworks.editor.collection.helper')", $blade);
        // second occurrence already TO — untouched count may still rewrite same literal once
        $this->assertSame(2, substr_count($blade, "__('common/ui.dimensions.full.depth')"));
    }

    public function test_rewrites_prefixed_helper_leaf_same_area(): void
    {
        $base = $this->makeTempDir();
        $views = $base.'/resources/views';
        mkdir($views, 0777, true);
        file_put_contents($views.'/accueil.blade.php', <<<'BLADE'
@php
    $homeText = static function (string $key, string $fallback = ''): string {
        $value = filled_trans('static pages/home.'.$key);
        return $value !== '' ? $value : $fallback;
    };
@endphp
<p>{{ $homeText('momentum_video.approach.description') }}</p>
<p>{{ $homeText('momentum_video.approach.title') }}</p>
BLADE);

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            [
                'static_pages.home.momentum_video.approach.description'
                    => 'static_pages.home.video.approach.description',
            ],
            [$base.'/resources'],
            [],
            dryRun: false,
            doLang: false,
            doCode: true,
        );

        $this->assertNotEmpty($summary['code']);
        $blade = (string) file_get_contents($views.'/accueil.blade.php');
        $this->assertStringContainsString("\$homeText('video.approach.description')", $blade);
        $this->assertStringNotContainsString("\$homeText('momentum_video.approach.description')", $blade);
        $this->assertStringContainsString("\$homeText('momentum_video.approach.title')", $blade);
    }

    public function test_rewrites_prefixed_helper_cross_area_to_direct_translator(): void
    {
        $base = $this->makeTempDir();
        $views = $base.'/resources/views';
        mkdir($views, 0777, true);
        file_put_contents($views.'/accueil.blade.php', <<<'BLADE'
@php
    $homeText = static function (string $key, string $fallback = ''): string {
        $value = filled_trans('static pages/home.'.$key);
        return $value !== '' ? $value : $fallback;
    };
@endphp
<p>{{ $homeText('momentum_video.approach.description', 'fallback') }}</p>
BLADE);

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            [
                'static_pages.home.momentum_video.approach.description'
                    => 'cctsst.momentum_video.approach.description',
            ],
            [$base.'/resources'],
            [],
            dryRun: false,
            doLang: false,
            doCode: true,
        );

        $this->assertNotEmpty($summary['code']);
        $blade = (string) file_get_contents($views.'/accueil.blade.php');
        $this->assertStringContainsString("__('cctsst/momentum_video.approach.description')", $blade);
        $this->assertStringNotContainsString("\$homeText('momentum_video.approach.description'", $blade);
    }

    public function test_rewrites_message_get_across_files(): void
    {
        $base = $this->makeTempDir();
        $app = $base.'/app';
        $views = $base.'/resources/views';
        mkdir($app, 0777, true);
        mkdir($views, 0777, true);
        file_put_contents($app.'/GalleryMessage.php', <<<'PHP'
<?php
class GalleryMessage {
    public static function get(string $key): string {
        return __('gallery.account.'.$key);
    }
}
PHP);
        file_put_contents($views.'/billing.blade.php', <<<'BLADE'
{{ GalleryMessage::get('new_billing_address.intro') }}
BLADE);

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            [
                'gallery.account.new_billing_address.intro' => 'billing.account.new_billing_address.intro',
            ],
            [$base.'/app', $base.'/resources'],
            [],
            dryRun: false,
            doLang: false,
            doCode: true,
        );

        $this->assertNotEmpty($summary['code']);
        $blade = (string) file_get_contents($views.'/billing.blade.php');
        $this->assertStringContainsString("__('billing/account.new_billing_address.intro')", $blade);
        $this->assertStringNotContainsString("GalleryMessage::get('new_billing_address.intro')", $blade);
    }

    public function test_rewrites_quoted_full_key_in_array_maps(): void
    {
        $base = $this->makeTempDir();
        $views = $base.'/resources/views';
        mkdir($views, 0777, true);
        file_put_contents($views.'/settings.blade.php', <<<'PHP'
<?php
$keys = [
    'profile' => 'root.settings.personal_settings.header.title',
];
$title = __($keys['profile']);
PHP);

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            [
                'root.settings.personal_settings.header.title' => 'domains.profile.header.title',
            ],
            [$base.'/resources'],
            [],
            dryRun: false,
            doLang: false,
            doCode: true,
        );

        $this->assertNotEmpty($summary['code']);
        $php = (string) file_get_contents($views.'/settings.blade.php');
        $this->assertStringContainsString("'domains.profile.header.title'", $php);
        $this->assertStringNotContainsString("'root.settings.personal_settings.header.title'", $php);
    }

    public function test_moves_lang_entry_from_spaced_folder_name(): void
    {
        $base = $this->makeTempDir();
        $frHome = $base.'/lang/fr/static pages';
        $frTarget = $base.'/lang/fr/cctsst';
        mkdir($frHome, 0777, true);
        mkdir($frTarget, 0777, true);
        file_put_contents(
            $frHome.'/home.php',
            "<?php\n\nreturn ['momentum_video' => ['approach' => ['description' => 'Texte', 'title' => 'Titre']]];\n",
        );
        file_put_contents($frTarget.'/momentum_video.php', "<?php\n\nreturn [];\n");

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            [
                'static_pages.home.momentum_video.approach.description'
                    => 'cctsst.momentum_video.approach.description',
            ],
            [],
            [$base.'/lang'],
            dryRun: false,
            doLang: true,
            doCode: false,
        );

        $this->assertNotEmpty($summary['lang']);
        $home = include $frHome.'/home.php';
        $target = include $frTarget.'/momentum_video.php';
        $this->assertTrue(! isset($home['momentum_video']['approach']['description']));
        $this->assertSame('Titre', $home['momentum_video']['approach']['title']);
        $this->assertSame('Texte', $target['approach']['description']);
    }

    public function test_dry_run_does_not_write_files(): void
    {
        $base = $this->makeTempDir();
        $lang = $base.'/lang/fr';
        mkdir($lang, 0777, true);
        $path = $lang.'/en.php';
        file_put_contents($path, "<?php\n\nreturn ['artwork_name' => 'Name'];\n");

        $service = new KeyRewriteService();
        $summary = $service->rewrite(
            ['en.artwork_name' => 'en.name_artwork'],
            [],
            [$base.'/lang'],
            dryRun: true,
            doLang: true,
            doCode: false,
        );

        $this->assertTrue($summary['dry_run']);
        $this->assertNotEmpty($summary['lang']);
        $this->assertStringContainsString('artwork_name', (string) file_get_contents($path));
    }

    public function test_nested_lang_path_replace(): void
    {
        $base = $this->makeTempDir();
        $lang = $base.'/lang/fr';
        mkdir($lang, 0777, true);
        file_put_contents($lang.'/domains.php', "<?php\n\nreturn ['explore' => ['artwork_name' => 'Nom', 'title' => 'T']];\n");

        $service = new KeyRewriteService();
        $service->rewrite(
            ['domains.explore.artwork_name' => 'domains.explore.name_artwork'],
            [],
            [$base.'/lang'],
            dryRun: false,
            doLang: true,
            doCode: false,
        );

        $data = include $lang.'/domains.php';
        $this->assertSame('Nom', $data['explore']['name_artwork']);
        $this->assertArrayNotHasKey('artwork_name', $data['explore']);
        $this->assertSame('T', $data['explore']['title']);
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/lexicon-rewrite-'.uniqid('', true);
        mkdir($dir);

        return $dir;
    }
}
