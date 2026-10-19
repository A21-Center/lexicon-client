<?php

namespace A21\LexiconClient\Scan;

use A21\LexiconClient\Http\LexiconHttpClient;
use A21\LexiconClient\Manifest\LexiconManifestReader;
use A21\LexiconClient\Support\KeyUsageScanner;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

class ScanUsageService
{
    public function __construct(
        private readonly LexiconManifestReader $manifestReader,
    ) {}

    /**
     * @param  array{
     *   application?: string|null,
     *   project_code?: string|null,
     *   areas?: list<string>|null,
     *   keys?: list<string>|null,
     *   paths?: list<string>|null,
     *   lang?: list<string>|null,
     *   catalog?: string|null,
     *   evidence_lang?: list<string>|null,
     *   replace_unmentioned?: bool,
     *   dry_run?: bool,
     * }  $options
     * @return array{
     *   success: bool,
     *   application: string,
     *   project_code: string,
     *   areas: list<string>,
     *   catalog_count: int,
     *   used: int,
     *   unused: int,
     *   matched?: int,
     *   missing_in_lexicon?: int,
     *   unmarked?: int,
     *   dry_run: bool,
     *   errors: list<string>,
     * }
     */
    public function scan(array $options = []): array
    {
        $config = $this->applyRuntimeEnvOverrides($this->manifestReader->mergedConfig());
        $projectCode = trim((string) ($options['project_code'] ?? $config['project_code'] ?? ''));
        if ($projectCode !== '') {
            $config['project_code'] = $projectCode;
        }

        $application = strtolower(trim((string) (
            $options['application']
            ?? $config['client_code']
            ?? 'app'
        )));
        if ($application === '') {
            throw new InvalidArgumentException('Application code is required.');
        }

        $catalogMode = strtolower(trim((string) ($options['catalog'] ?? 'lexicon')));
        if (! in_array($catalogMode, ['lang', 'lexicon'], true)) {
            throw new InvalidArgumentException('Invalid catalog value. Use lang or lexicon.');
        }

        $areas = array_values(array_filter(array_map(
            static fn ($code): string => strtolower(trim((string) $code)),
            (array) ($options['areas'] ?? []),
        )));
        $explicitKeys = array_values(array_unique(array_filter(array_map(
            static fn ($key): string => str_replace('/', '.', trim((string) $key)),
            (array) ($options['keys'] ?? []),
        ))));

        $dryRun = (bool) ($options['dry_run'] ?? false);
        $replaceUnmentioned = array_key_exists('replace_unmentioned', $options)
            ? (bool) $options['replace_unmentioned']
            : true;

        if (! $dryRun) {
            $this->assertCredentials($config);
        }

        $scanner = new KeyUsageScanner();
        $roots = $this->resolveRoots((array) ($options['paths'] ?? []));
        $client = new LexiconHttpClient($config);

        if ($explicitKeys !== []) {
            $catalog = $explicitKeys;
        } elseif ($catalogMode === 'lexicon') {
            $catalog = $this->resolveLexiconCatalogKeys(
                $client,
                (string) ($config['project_code'] ?? ''),
                $areas,
            );
        } else {
            $catalog = $this->resolveCatalogKeys($scanner, (array) ($options['lang'] ?? []));
            if ($areas !== []) {
                $catalog = $this->filterCatalogByAreas($catalog, $areas);
            }
        }

        if ($catalog === []) {
            throw new RuntimeException($catalogMode === 'lexicon'
                ? 'No catalog keys returned by Lexicon for this project/area.'
                : 'No catalog keys found from lang files.');
        }

        $found = $scanner->scan($roots);
        if ($catalogMode === 'lexicon' || $explicitKeys !== []) {
            $found = $scanner->expandMessageHelperUsage($roots, $found);
        }
        $found = $this->mergeEvidenceLangUsage(
            $scanner,
            $found,
            (array) ($options['evidence_lang'] ?? []),
        );

        $results = $this->normalizeResults($scanner->buildResults($catalog, $found));

        $used = count(array_filter($results, static fn (array $row): bool => $row['status'] === 'used'));
        $unused = count($results) - $used;

        $summary = [
            'success' => true,
            'application' => $application,
            'project_code' => (string) ($config['project_code'] ?? ''),
            'areas' => $areas,
            'catalog_count' => count($catalog),
            'used' => $used,
            'unused' => $unused,
            'dry_run' => $dryRun,
            'errors' => [],
        ];

        if ($dryRun) {
            return $summary;
        }

        $report = $client->reportUsage([
            'application' => $application,
            'project_code' => (string) ($config['project_code'] ?? ''),
            'areas' => $areas,
            'replace_unmentioned' => $replaceUnmentioned,
            'results' => $results,
        ]);

        return array_merge($summary, [
            'matched' => (int) ($report['matched'] ?? count($results)),
            'used' => (int) ($report['used'] ?? $used),
            'unused' => (int) ($report['unused'] ?? $unused),
            'missing_in_lexicon' => (int) ($report['missing_in_lexicon'] ?? 0),
            'unmarked' => (int) ($report['unmarked'] ?? 0),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function assertCredentials(array $config): void
    {
        foreach (['api_url', 'client_code', 'secret', 'project_code'] as $key) {
            if (! filled($config[$key] ?? null)) {
                throw new InvalidArgumentException(
                    "Missing Lexicon credential [{$key}] for usage scan report."
                );
            }
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function resolveRoots(array $paths): array
    {
        $paths = array_values(array_filter(array_map('strval', $paths)));
        if ($paths === []) {
            // Include public/js: Studio/Gallery front helpers use t('common.ui...') there.
            $paths = array_values(array_filter([
                base_path('resources'),
                base_path('app'),
                base_path('src'),
                base_path('public/js'),
                base_path('resources/js'),
            ], 'is_dir'));
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyRuntimeEnvOverrides(array $config): array
    {
        $map = [
            'api_url' => 'LEXICON_API_URL',
            'client_code' => 'LEXICON_CLIENT_CODE',
            'secret' => 'LEXICON_CLIENT_SECRET',
            'project_code' => 'LEXICON_PROJECT_CODE',
            'environment' => 'LEXICON_ENVIRONMENT',
        ];

        foreach ($map as $key => $envKey) {
            $value = $_SERVER[$envKey] ?? $_ENV[$envKey] ?? getenv($envKey);
            if (is_string($value) && $value !== '') {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    /**
     * @return list<string>
     */
    private function resolveLexiconCatalogKeys(LexiconHttpClient $client, string $projectCode, array $areas): array
    {
        if ($projectCode === '') {
            throw new RuntimeException('LEXICON_PROJECT_CODE is required when catalog=lexicon.');
        }

        $payload = $client->keyCatalog($projectCode, $areas);
        $keys = $payload['keys'] ?? [];
        if (! is_array($keys)) {
            return [];
        }

        $normalized = [];
        foreach ($keys as $key) {
            if (! is_string($key)) {
                continue;
            }
            $key = str_replace('/', '.', trim($key));
            if ($key !== '') {
                $normalized[] = $key;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param  list<string>  $langOptions
     * @return list<string>
     */
    private function resolveCatalogKeys(KeyUsageScanner $scanner, array $langOptions): array
    {
        $langOptions = array_values(array_filter(array_map('strval', $langOptions)));
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
     * @param  list<string>  $catalog
     * @param  list<string>  $areas
     * @return list<string>
     */
    private function filterCatalogByAreas(array $catalog, array $areas): array
    {
        $prefixes = array_map(static fn (string $area): string => rtrim($area, '.').'.', $areas);

        return array_values(array_filter($catalog, static function (string $key) use ($areas, $prefixes): bool {
            if (in_array($key, $areas, true)) {
                return true;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($key, $prefix)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @param  array<string, list<array{path: string, line: int}>>  $found
     * @param  list<string>  $evidenceOptions
     * @return array<string, list<array{path: string, line: int}>>
     */
    private function mergeEvidenceLangUsage(KeyUsageScanner $scanner, array $found, array $evidenceOptions): array
    {
        $evidenceOptions = array_values(array_filter(array_map('strval', $evidenceOptions)));
        if ($evidenceOptions === []) {
            return $found;
        }

        $files = [];
        /** @var array<string, string> $groupPrefixes */
        $groupPrefixes = [];
        /** @var array<string, string> $fileEvidencePath */
        $fileEvidencePath = [];

        foreach ($evidenceOptions as $option) {
            if (is_file($option)) {
                $files[] = $option;
                $groupPrefixes[$option] = $this->guessGroupPrefix($option);
                $fileEvidencePath[$option] = $this->relativeEvidencePath($option);

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
                $fileEvidencePath[$path] = $this->relativeEvidencePath($path);
            }
        }

        foreach ($files as $file) {
            $keys = $scanner->collectKeysFromTranslationFiles([$file], [
                $file => $groupPrefixes[$file] ?? '',
            ]);
            $evidencePath = $fileEvidencePath[$file] ?? $file;
            foreach ($keys as $key) {
                $normalized = str_replace('/', '.', (string) $key);
                $variants = array_values(array_unique(array_filter([
                    $normalized,
                    $scanner->normalizeKeyForLexicon($normalized),
                    preg_replace('/^cs\./', '', $normalized) ?? $normalized,
                ])));
                foreach ($variants as $variant) {
                    $found[$variant] ??= [];
                    $found[$variant][] = [
                        'path' => $evidencePath,
                        'line' => 1,
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<array{full_key: string, status: string, evidence?: list<array{path: string, line?: int}>}>  $results
     * @return list<array{full_key: string, status: string, evidence: list<array{path: string, line?: int}>}>
     */
    private function normalizeResults(array $results): array
    {
        $results = array_map(static function (array $row): array {
            $row['full_key'] = str_replace('/', '.', (string) $row['full_key']);

            return $row;
        }, $results);

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

        return array_values($deduped);
    }

    private function relativeEvidencePath(string $path): string
    {
        $base = str_replace('\\', '/', base_path());
        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, $base.'/')) {
            return substr($normalized, strlen($base) + 1);
        }

        return $normalized;
    }

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
