<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Http\LexiconHttpClient;
use A21\LexiconClient\Manifest\LexiconManifestReader;
use A21\LexiconClient\Support\KeyUsageScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ScanUsageCommand extends Command
{
    use EnsuresLexiconCredentials;

    protected $signature = 'lexicon:scan-usage
        {--application= : Application code (studio|hub|gallery|custom)}
        {--path=* : Extra roots to scan (defaults: resources, app, src)}
        {--lang=* : Translation file/dir globs for exact catalog keys}
        {--dry-run : Scan and print summary without posting to Lexicon}
        {--no-replace-unmentioned : Do not mark Lexicon keys absent from catalog as unused}';

    protected $description = 'Scan exact translation key literals in client code and report Used/Unused to Lexicon.';

    public function handle(LexiconManifestReader $manifestReader): int
    {
        $config = $manifestReader->mergedConfig();
        if (! $this->ensureLexiconCredentials($config) && ! $this->option('dry-run')) {
            return self::FAILURE;
        }

        $application = strtolower(trim((string) ($this->option('application') ?: ($config['client_code'] ?? 'app'))));
        if ($application === '') {
            $this->error('Application code is required (--application or LEXICON_CLIENT_CODE).');

            return self::FAILURE;
        }

        $scanner = new KeyUsageScanner();
        $roots = $this->resolveRoots();
        $catalog = $this->resolveCatalogKeys($scanner);
        if ($catalog === []) {
            $this->warn('No catalog keys found from --lang files. Provide --lang paths to translation files.');

            return self::FAILURE;
        }

        $this->info(sprintf('Scanning %d root(s) against %d catalog key(s)…', count($roots), count($catalog)));
        $found = $scanner->scan($roots);
        $results = $scanner->buildResults($catalog, $found);
        $results = array_map(static function (array $row): array {
            $row['full_key'] = str_replace('/', '.', (string) $row['full_key']);

            return $row;
        }, $results);
        // Deduplicate after slash→dot normalization (same Lexicon key from alternate paths).
        $deduped = [];
        foreach ($results as $row) {
            $key = $row['full_key'];
            if (! isset($deduped[$key]) || $row['status'] === 'used') {
                if (isset($deduped[$key]) && $deduped[$key]['status'] === 'used' && $row['status'] === 'used') {
                    $deduped[$key]['evidence'] = array_values(array_merge(
                        $deduped[$key]['evidence'] ?? [],
                        $row['evidence'] ?? [],
                    ));
                } else {
                    $deduped[$key] = $row;
                }
            }
        }
        $results = array_values($deduped);

        $used = count(array_filter($results, static fn (array $row): bool => $row['status'] === 'used'));
        $unused = count($results) - $used;
        $this->line("Used: {$used}");
        $this->line("Unused: {$unused}");

        if ($this->option('dry-run')) {
            $this->comment('Dry-run: report not sent.');

            return self::SUCCESS;
        }

        $client = new LexiconHttpClient($config);
        $summary = $client->reportUsage([
            'application' => $application,
            'project_code' => (string) ($config['project_code'] ?? ''),
            'replace_unmentioned' => ! $this->option('no-replace-unmentioned'),
            'results' => $results,
        ]);
        $this->info('Lexicon usage report accepted.');
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function resolveRoots(): array
    {
        $paths = array_values(array_filter(array_map('strval', (array) $this->option('path'))));
        if ($paths === []) {
            $paths = array_values(array_filter([
                base_path('resources'),
                base_path('app'),
                base_path('src'),
            ], 'is_dir'));
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function resolveCatalogKeys(KeyUsageScanner $scanner): array
    {
        $langOptions = array_values(array_filter(array_map('strval', (array) $this->option('lang'))));
        $files = [];
        /** @var array<string, string> $groupPrefixes */
        $groupPrefixes = [];

        if ($langOptions === []) {
            foreach (array_values(array_filter([
                function_exists('lang_path') ? lang_path() : null,
                resource_path('lang'),
                base_path('lang'),
            ], static fn ($dir) => is_string($dir) && is_dir($dir))) as $dir) {
                $langOptions[] = $dir;
            }
        }

        foreach ($langOptions as $option) {
            if (is_file($option)) {
                $files[] = $option;
                $groupPrefixes[$option] = $this->guessGroupPrefix($option);

                continue;
            }
            if (! is_dir($option)) {
                continue;
            }
            foreach (File::allFiles($option) as $file) {
                $ext = strtolower($file->getExtension());
                if (! in_array($ext, ['php', 'json'], true)) {
                    continue;
                }
                $path = $file->getPathname();
                $files[] = $path;
                $groupPrefixes[$path] = $this->guessGroupPrefix($path, $option);
            }
        }

        return $scanner->collectKeysFromTranslationFiles($files, $groupPrefixes);
    }

    /**
     * Derive Laravel translation group from a lang file path.
     * lang/en/root/auth.php → root/auth
     * lang/en.json → '' (JSON keys are already full keys)
     */
    private function guessGroupPrefix(string $file, ?string $langRoot = null): string
    {
        $normalized = str_replace('\\', '/', $file);
        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
        if ($extension === 'json') {
            return '';
        }

        if ($langRoot !== null) {
            $root = rtrim(str_replace('\\', '/', $langRoot), '/').'/';
            if (str_starts_with($normalized, $root)) {
                $relative = substr($normalized, strlen($root));
                $relative = preg_replace('#^[a-zA-Z0-9_-]+/#', '', $relative) ?? $relative;
                $relative = preg_replace('#\.php$#', '', $relative) ?? $relative;

                return trim($relative, '/');
            }
        }

        if (preg_match('#/(?:lang|resources/lang)/[a-zA-Z0-9_-]+/(.+)\.php$#', $normalized, $matches) === 1) {
            return trim($matches[1], '/');
        }

        return pathinfo($normalized, PATHINFO_FILENAME) ?: '';
    }
}
