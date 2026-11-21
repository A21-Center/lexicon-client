<?php

namespace A21\LexiconClient\Rewrite;

use A21\LexiconClient\Files\PhpArrayEncoder;
use A21\LexiconClient\Support\KeyUsageScanner;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Rewrite translation key literals in lang files and source code.
 *
 * Pipeline used by Lexicon Move:
 * 1) scan lang + Blade/JS/PHP for FROM usages (exact + prefixed helpers)
 * 2) move lang entries (all locales)
 * 3) replace exact literals and helper leaves / cross-area calls in code
 *
 * Limitation (package users): fully runtime-dynamic keys cannot be rewritten, e.g.
 *   $key = $request->input('field'); echo __($key);
 * Open prefixes like __('errors.'.$code) may mark catalog keys Used but Move still
 * cannot rewrite a single leaf inside that call. Skipped reason:
 *   code-dynamic-unrewritable:<full_key>
 * Prefer literals or prefix+leaf helpers so Move can update client code safely.
 */
class KeyRewriteService
{
    /** @var array<string, string>|null class => normalized prefix */
    private ?array $projectMessagePrefixes = null;

    public function __construct(
        private readonly KeyUsageScanner $scanner = new KeyUsageScanner(),
        private readonly PhpArrayEncoder $phpEncoder = new PhpArrayEncoder(),
    ) {}

    /**
     * @param  array<string, string>  $map  from_full_key => to_full_key
     * @param  list<string>  $codeRoots
     * @param  list<string>  $langRoots
     * @return array{
     *   dry_run: bool,
     *   map: array<string, string>,
     *   lang: list<array{path: string, replacements: int}>,
     *   code: list<array{path: string, replacements: int}>,
     *   skipped: list<string>,
     * }
     */
    /**
     * @param  array<string, string>  $map  from_full_key => to_full_key
     * @param  list<string>  $codeRoots
     * @param  list<string>  $langRoots
     * @param  array<string, array<string, string>>  $values  from_full_key => [locale => text]
     * @return array{
     *   dry_run: bool,
     *   map: array<string, string>,
     *   keep_aliases: bool,
     *   scan: array<string, mixed>,
     *   lang: list<array{path: string, replacements: int}>,
     *   code: list<array{path: string, replacements: int}>,
     *   skipped: list<string>,
     * }
     */
    public function rewrite(
        array $map,
        array $codeRoots = [],
        array $langRoots = [],
        bool $dryRun = true,
        bool $doLang = true,
        bool $doCode = true,
        bool $keepAliases = false,
        array $values = [],
    ): array {
        $map = $this->normalizeMap($map);
        if ($map === []) {
            throw new InvalidArgumentException('Replace map is empty.');
        }

        $langHits = [];
        $codeHits = [];
        $skipped = [];
        $this->projectMessagePrefixes = null;
        $resolvedCodeRoots = $this->defaultCodeRoots($codeRoots);
        $resolvedLangRoots = $this->defaultLangRoots($langRoots);

        // Scan first so Move callers can see Blade/JS hits before/with the rewrite.
        $scan = [
            'lang_roots' => array_map(fn (string $root): string => $this->relativePath($root), $resolvedLangRoots),
            'code_roots' => array_map(fn (string $root): string => $this->relativePath($root), $resolvedCodeRoots),
            'code_files' => $doCode ? $this->scanCodeFiles($map, $resolvedCodeRoots) : [],
        ];

        if ($doLang) {
            $langHits = $this->rewriteLangFiles($map, $resolvedLangRoots, $dryRun, $skipped, $keepAliases, $values);
        }

        if ($doCode) {
            $codeHits = $this->rewriteCodeFiles($map, $resolvedCodeRoots, $dryRun, $skipped);
        }

        return [
            'dry_run' => $dryRun,
            'map' => $map,
            'keep_aliases' => $keepAliases,
            'scan' => $scan,
            'lang' => $langHits,
            'code' => $codeHits,
            'skipped' => array_values(array_unique($skipped)),
        ];
    }

    /**
     * @param  array<string, string>  $map
     * @param  list<string>  $roots
     * @return list<string> relative file paths that contain a FROM literal
     */
    private function scanCodeFiles(array $map, array $roots): array
    {
        if ($roots === [] || $map === []) {
            return [];
        }

        $found = $this->collectCodeUsages($roots);
        $files = [];
        foreach (array_keys($map) as $from) {
            foreach ($this->usageRowsForKey($found, (string) $from) as $row) {
                if (! empty($row['path'])) {
                    $files[(string) $row['path']] = true;
                }
            }
        }

        $paths = array_keys($files);
        sort($paths);

        return $paths;
    }

    /**
     * Exact literals plus Message/helper / concatenated-prefix usages.
     *
     * @param  list<string>  $roots
     * @return array<string, list<array{path: string, line: int}>>
     */
    private function collectCodeUsages(array $roots): array
    {
        $found = $this->scanner->scan($roots);
        $found = $this->scanner->expandMessageHelperUsage($roots, $found);

        return $this->scanner->expandConcatenatedPrefixUsage($roots, $found);
    }

    /**
     * @param  array<string, list<array{path: string, line: int}>>  $found
     * @return list<array{path: string, line: int}>
     */
    private function usageRowsForKey(array $found, string $from): array
    {
        $rows = [];
        $targets = [];
        foreach ($this->keyVariants($from) as $variant) {
            $targets[$variant] = true;
            $normalized = $this->scanner->normalizeKeyForLexicon($variant);
            $targets[$normalized] = true;
            $targets[strtolower($normalized)] = true;
        }

        foreach ($found as $rawKey => $evidence) {
            $raw = (string) $rawKey;
            $normalized = $this->scanner->normalizeKeyForLexicon($raw);
            if (! isset($targets[$raw]) && ! isset($targets[$normalized]) && ! isset($targets[strtolower($normalized)])) {
                continue;
            }
            foreach ($evidence as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    private function normalizeMap(array $map): array
    {
        $normalized = [];
        foreach ($map as $from => $to) {
            $fromKey = trim(str_replace('/', '.', (string) $from));
            $toKey = trim(str_replace('/', '.', (string) $to));
            if ($fromKey === '' || $toKey === '' || $fromKey === $toKey) {
                continue;
            }
            $normalized[$fromKey] = $toKey;
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function defaultLangRoots(array $roots): array
    {
        if ($roots !== []) {
            return $roots;
        }

        $candidates = [base_path('lang'), base_path('resources/lang')];

        return array_values(array_filter($candidates, 'is_dir'));
    }

    /**
     * @param  list<string>  $roots
     * @return list<string>
     */
    private function defaultCodeRoots(array $roots): array
    {
        if ($roots !== []) {
            return $roots;
        }

        $candidates = [
            base_path('resources'),
            base_path('app'),
            base_path('src'),
            base_path('themes'),
            // Studio keeps t()/i18n literals under public/js (and other public assets).
            base_path('public'),
        ];

        return array_values(array_filter($candidates, 'is_dir'));
    }

    /**
     * @param  array<string, string>  $map
     * @param  list<string>  $roots
     * @param  list<string>  $skipped
     * @return list<array{path: string, replacements: int}>
     */
    /**
     * @param  array<string, string>  $map
     * @param  list<string>  $roots
     * @param  array<string, array<string, string>>  $values
     */
    private function rewriteLangFiles(array $map, array $roots, bool $dryRun, array &$skipped, bool $keepAliases = false, array $values = []): array
    {
        /** @var array<string, array{path: string, extension: string, prefix: string, data: array<string, mixed>}> $files */
        $files = [];

        foreach ($roots as $root) {
            $root = rtrim($root, DIRECTORY_SEPARATOR);
            if ($root === '' || ! is_dir($root)) {
                continue;
            }

            foreach ($this->iterateFiles([$root], ['php', 'json']) as $file) {
                $path = $file->getPathname();
                $extension = strtolower($file->getExtension());
                $prefix = $this->areaPrefixFromLangPath($path, $root);
                if ($extension === 'json') {
                    $decoded = json_decode((string) file_get_contents($path), true);
                    $data = is_array($decoded) ? $decoded : [];
                } else {
                    $included = include $path;
                    $data = is_array($included) ? $included : [];
                }
                $files[$path] = [
                    'path' => $path,
                    'extension' => $extension,
                    'prefix' => $prefix,
                    'data' => $data,
                ];
            }
        }

        $counts = [];
        foreach ($map as $from => $to) {
            // Apply per locale file — findLangKey alone would only rewrite the first match (often en).
            $fromHits = $this->findAllLangKeys($files, $from);
            if ($fromHits === []) {
                $seeded = $this->seedLangEntriesFromValues($files, $from, $to, $values[$from] ?? [], $skipped);
                if ($seeded === []) {
                    $skipped[] = 'lang-missing:'.$from;
                    continue;
                }
                foreach ($seeded as $path) {
                    $counts[$path] = ($counts[$path] ?? 0) + 1;
                }
                continue;
            }

            foreach ($fromHits as $fromHit) {
                $moved = $this->moveLangEntryAt($files, $fromHit, $to, $skipped, $keepAliases);
                if ($moved === null) {
                    continue;
                }
                foreach ($moved as $path) {
                    $counts[$path] = ($counts[$path] ?? 0) + 1;
                }
            }
        }

        $hits = [];
        foreach ($counts as $path => $replacements) {
            $file = $files[$path] ?? null;
            if ($file === null) {
                continue;
            }
            if (! $dryRun) {
                try {
                    $dir = dirname($path);
                    if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                        $skipped[] = 'lang-write-denied:'.$this->relativePath($path).' (mkdir failed)';
                        continue;
                    }
                    $payload = $file['extension'] === 'json'
                        ? json_encode($file['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
                        : $this->phpEncoder->encode($file['data']);
                    if (@file_put_contents($path, $payload) === false) {
                        $skipped[] = 'lang-write-denied:'.$this->relativePath($path);
                        continue;
                    }
                } catch (\Throwable $exception) {
                    $skipped[] = 'lang-write-denied:'.$this->relativePath($path).' ('.$exception->getMessage().')';
                    continue;
                }
            }
            $hits[] = [
                'path' => $this->relativePath($path),
                'replacements' => $replacements,
            ];
        }

        return $hits;
    }

    /**
     * @param  array<string, array{path: string, extension: string, prefix: string, data: array<string, mixed>}>  $files
     * @param  array{path: string, local: string, locale: string}  $fromHit
     * @param  list<string>  $skipped
     * @return list<string>|null  touched paths
     */
    private function moveLangEntryAt(array &$files, array $fromHit, string $to, array &$skipped, bool $keepAliases = false): ?array
    {
        $value = $this->getNestedValue($files[$fromHit['path']]['data'], $fromHit['local']);
        if (! $keepAliases) {
            $this->forgetNestedValue($files[$fromHit['path']]['data'], $fromHit['local']);
        }

        $toHit = $this->resolveLangTargetForLocale($files, $to, $fromHit['path'], $fromHit['locale']);
        if ($toHit === null) {
            $skipped[] = 'lang-target:'.$to.'@'.$fromHit['locale'];
            if (! $keepAliases) {
                $this->setNestedValue($files[$fromHit['path']]['data'], $fromHit['local'], $value);
            }

            return null;
        }

        $existing = $this->getNestedValue($files[$toHit['path']]['data'], $toHit['local']);
        $touched = [$fromHit['path']];
        // Keep TO translations: only write TO file when the key is missing there.
        if ($existing === null) {
            $this->setNestedValue($files[$toHit['path']]['data'], $toHit['local'], $value);
            if ($toHit['path'] !== $fromHit['path']) {
                $touched[] = $toHit['path'];
            }
        }

        return $touched;
    }

    /**
     * @param  array<string, array{path: string, extension: string, prefix: string, data: array<string, mixed>}>  $files
     * @return list<array{path: string, local: string, locale: string}>
     */
    private function findAllLangKeys(array $files, string $fullKey): array
    {
        $hits = [];
        foreach ($files as $path => $file) {
            $prefix = $file['prefix'];
            $candidates = [];
            if ($prefix !== '' && str_starts_with($fullKey, $prefix.'.')) {
                $local = substr($fullKey, strlen($prefix) + 1);
                $candidates = $this->localPathFallbacks($local);
            } elseif ($this->getNestedValue($file['data'], $fullKey) !== null) {
                $candidates = [$fullKey];
            }

            $matchedLocal = null;
            foreach ($candidates as $candidate) {
                if ($this->getNestedValue($file['data'], $candidate) !== null) {
                    $matchedLocal = $candidate;
                    break;
                }
            }

            if ($matchedLocal === null) {
                continue;
            }

            $hits[] = [
                'path' => $path,
                'local' => $matchedLocal,
                'locale' => $this->localeFromLangPath($path),
            ];
        }

        return $hits;
    }

    /**
     * Lexicon key_path can be nested (accessibility.list_view) while lang files store
     * the value flatter (list_view). Try longest path first, then suffixes.
     *
     * @return list<string>
     */
    private function localPathFallbacks(string $local): array
    {
        $local = trim(str_replace('/', '.', $local), '.');
        if ($local === '') {
            return [];
        }

        $parts = explode('.', $local);
        $candidates = [];
        for ($i = 0; $i < count($parts); $i++) {
            $candidates[] = implode('.', array_slice($parts, $i));
        }

        return array_values(array_unique($candidates));
    }

    /**
     * When FROM is absent on disk, still write TO using Lexicon-provided locale values.
     *
     * @param  array<string, array{path: string, extension: string, prefix: string, data: array<string, mixed>}>  $files
     * @param  array<string, string>  $localeValues
     * @param  list<string>  $skipped
     * @return list<string>
     */
    private function seedLangEntriesFromValues(
        array &$files,
        string $from,
        string $to,
        array $localeValues,
        array &$skipped,
    ): array {
        if ($localeValues === []) {
            return [];
        }

        $touched = [];
        foreach ($localeValues as $locale => $value) {
            $locale = strtolower(trim((string) $locale));
            $text = is_scalar($value) ? (string) $value : '';
            if ($locale === '' || $text === '') {
                continue;
            }

            $target = null;
            foreach ($files as $path => $file) {
                if ($this->localeFromLangPath($path) !== $locale) {
                    continue;
                }
                $prefix = $file['prefix'];
                if ($prefix !== '' && str_starts_with($to, $prefix.'.')) {
                    $target = ['path' => $path, 'local' => substr($to, strlen($prefix) + 1)];
                    break;
                }
            }

            if ($target === null) {
                // Create lang/{locale}/{area}.php from dotted area code when missing.
                $created = $this->ensureLangFileForKey($files, $to, $locale);
                if ($created === null) {
                    $skipped[] = 'lang-target-seed:'.$to.'@'.$locale;
                    continue;
                }
                $target = $created;
            }

            $existing = $this->getNestedValue($files[$target['path']]['data'], $target['local']);
            if ($existing !== null) {
                continue;
            }
            $this->setNestedValue($files[$target['path']]['data'], $target['local'], $text);
            $touched[] = $target['path'];
        }

        return array_values(array_unique($touched));
    }

    /**
     * @param  array<string, array{path: string, extension: string, prefix: string, data: array<string, mixed>}>  $files
     * @return array{path: string, local: string}|null
     */
    private function ensureLangFileForKey(array &$files, string $fullKey, string $locale, ?string $hintPath = null): ?array
    {
        $parts = explode('.', trim(str_replace('/', '.', $fullKey), '.'));
        if (count($parts) < 2) {
            return null;
        }

        // Prefer area = first two segments when present (common.ui.* / a.b.*), else first segment.
        $areaCount = count($parts) >= 3 ? 2 : 1;
        $areaSegments = array_slice($parts, 0, $areaCount);
        $localParts = array_slice($parts, $areaCount);
        if ($localParts === []) {
            return null;
        }

        $roots = [];
        if (is_string($hintPath) && $hintPath !== '') {
            $hintRoot = $this->langRootFromFilePath($hintPath);
            if ($hintRoot !== null) {
                $roots[] = $hintRoot;
            }
        }
        foreach ($files as $path => $_) {
            $root = $this->langRootFromFilePath($path);
            if ($root !== null) {
                $roots[] = $root;
            }
        }
        $roots = array_values(array_unique([...$roots, ...$this->defaultLangRoots([])]));

        foreach ($roots as $root) {
            $root = rtrim($root, DIRECTORY_SEPARATOR);
            if ($root === '' || ! is_dir($root)) {
                continue;
            }
            $relative = $locale.'/'.implode('/', $areaSegments).'.php';
            $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! isset($files[$absolute])) {
                $existing = [];
                if (is_file($absolute)) {
                    $included = include $absolute;
                    $existing = is_array($included) ? $included : [];
                }
                $files[$absolute] = [
                    'path' => $absolute,
                    'extension' => 'php',
                    'prefix' => implode('.', $areaSegments),
                    'data' => $existing,
                ];
            }

            return [
                'path' => $absolute,
                'local' => implode('.', $localParts),
            ];
        }

        return null;
    }

    private function langRootFromFilePath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);
        if (preg_match('#^(.*?/(?:lang|resources/lang))(?:/|$)#', $normalized, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function localeFromLangPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (preg_match('#/(?:lang|resources/lang)/([^/]+)/#', $normalized, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Prefer the TO file in the same locale as FROM.
     *
     * @param  array<string, array{path: string, extension: string, prefix: string, data: array<string, mixed>}>  $files
     * @return array{path: string, local: string}|null
     */
    private function resolveLangTargetForLocale(array &$files, string $fullKey, string $fallbackPath, string $locale): ?array
    {
        $best = null;
        $bestLen = -1;
        foreach ($files as $path => $file) {
            if ($locale !== '' && $this->localeFromLangPath($path) !== $locale) {
                continue;
            }
            $prefix = $file['prefix'];
            if ($prefix !== '' && str_starts_with($fullKey, $prefix.'.')) {
                $len = strlen($prefix);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = ['path' => $path, 'local' => substr($fullKey, $len + 1)];
                }
            }
        }

        if ($best !== null) {
            return $best;
        }

        // Cross-area Move: create lang/{locale}/{area}.php instead of writing TO into the FROM file.
        $created = $this->ensureLangFileForKey($files, $fullKey, $locale, $fallbackPath);
        if ($created !== null) {
            return $created;
        }

        return [
            'path' => $fallbackPath,
            'local' => str_contains($fullKey, '.')
                ? substr($fullKey, (int) strrpos($fullKey, '.') + 1)
                : $fullKey,
        ];
    }

    private function areaPrefixFromLangPath(string $path, string $langRoot): string
    {
        $normalized = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $langRoot), '/');
        if (! str_starts_with($normalized, $root.'/')) {
            return '';
        }
        $relative = substr($normalized, strlen($root) + 1);
        $parts = explode('/', $relative);
        if ($parts === []) {
            return '';
        }
        // drop locale segment
        array_shift($parts);
        if ($parts === []) {
            return '';
        }
        $file = array_pop($parts) ?? '';
        $file = preg_replace('/\.(php|json)$/i', '', $file) ?? $file;
        $segments = array_values(array_filter([...$parts, $file], static fn ($part) => $part !== ''));

        // Normalize "static pages/home" → static_pages.home so Lexicon full_keys match.
        return $this->scanner->normalizeKeyForLexicon(implode('.', $segments));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function getNestedValue(array $data, string $dotted): mixed
    {
        $cursor = $data;
        foreach (explode('.', $dotted) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function setNestedValue(array &$data, string $dotted, mixed $value): void
    {
        $segments = explode('.', $dotted);
        $cursor = &$data;
        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;

                return;
            }
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function forgetNestedValue(array &$data, string $dotted): void
    {
        $segments = explode('.', $dotted);
        $cursor = &$data;
        $stack = [];
        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                unset($cursor[$segment]);
                break;
            }
            if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                return;
            }
            $stack[] = [&$cursor, $segment];
            $cursor = &$cursor[$segment];
        }

        // prune empty parents
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            [$parent, $key] = $stack[$i];
            if (isset($parent[$key]) && is_array($parent[$key]) && $parent[$key] === []) {
                unset($parent[$key]);
            }
        }
    }

    /**
     * @param  array<string, string>  $map
     * @param  list<string>  $roots
     * @param  list<string>  $skipped
     * @return list<array{path: string, replacements: int}>
     */
    private function rewriteCodeFiles(array $map, array $roots, bool $dryRun, array &$skipped): array
    {
        $found = $this->collectCodeUsages($roots);
        $messagePrefixes = $this->projectMessagePrefixes($roots);
        $hitsByFile = [];

        foreach ($map as $from => $to) {
            // Merge evidence for ALL variants (JS dots + Blade hybrid slash forms).
            // Taking only the first match previously skipped Blade when JS matched first.
            $byFile = [];
            $variantPairs = [];
            foreach ($this->rewritableKeyVariants($from) as $fromVariant) {
                $variantPairs[] = [
                    'from' => $fromVariant,
                    'to' => $this->alignVariantShape($fromVariant, $to),
                ];
                if (! isset($found[$fromVariant])) {
                    continue;
                }
                foreach ($found[$fromVariant] as $row) {
                    if (! empty($row['path'])) {
                        $byFile[(string) $row['path']] = true;
                    }
                }
            }

            // Prefixed helpers / Message::get may only appear under normalized full keys.
            foreach ($this->usageRowsForKey($found, $from) as $row) {
                if (! empty($row['path'])) {
                    $byFile[(string) $row['path']] = true;
                }
            }

            // Brute-force: any file that still contains a quoted full-key variant.
            foreach ($this->filesContainingQuotedVariants($roots, $from) as $relative) {
                $byFile[$relative] = true;
            }

            if ($byFile === []) {
                if ($this->isCoveredByOpenDynamicUsage($found, $from)) {
                    $skipped[] = 'code-dynamic-unrewritable:'.$from;
                } else {
                    $skipped[] = 'code-missing:'.$from;
                }
                continue;
            }

            $keyHits = 0;
            foreach (array_keys($byFile) as $relative) {
                $absolute = is_file($relative) ? $relative : $this->absoluteFromRelative($relative, $roots);
                if ($absolute === null || ! is_file($absolute)) {
                    $skipped[] = $relative;
                    continue;
                }

                $contents = (string) file_get_contents($absolute);
                $count = 0;
                $updated = $contents;
                foreach ($variantPairs as $pair) {
                    $updated = $this->replaceExactLiterals(
                        $updated,
                        $pair['from'],
                        $pair['to'],
                        $count,
                    );
                }
                $updated = $this->rewritePrefixedHelperUsages($updated, $from, $to, $count);
                $updated = $this->rewriteMessageHelperUsages($updated, $from, $to, $count, $messagePrefixes);
                foreach ($variantPairs as $pair) {
                    $updated = $this->replaceQuotedFullKeyLiterals(
                        $updated,
                        $pair['from'],
                        $pair['to'],
                        $count,
                    );
                }
                if ($count === 0) {
                    continue;
                }

                $keyHits += $count;

                if (! $dryRun) {
                    try {
                        if (@file_put_contents($absolute, $updated) === false) {
                            $skipped[] = 'code-write-denied:'.$this->relativePath($absolute);
                            continue;
                        }
                    } catch (\Throwable $exception) {
                        $skipped[] = 'code-write-denied:'.$this->relativePath($absolute).' ('.$exception->getMessage().')';
                        continue;
                    }
                }

                $rel = $this->relativePath($absolute);
                $hitsByFile[$rel] = ($hitsByFile[$rel] ?? 0) + $count;
            }

            if ($keyHits === 0 && $this->isCoveredByOpenDynamicUsage($found, $from)) {
                $skipped[] = 'code-dynamic-unrewritable:'.$from;
            }
        }

        $hits = [];
        foreach ($hitsByFile as $path => $replacements) {
            $hits[] = ['path' => $path, 'replacements' => $replacements];
        }

        return $hits;
    }

    /**
     * Variants safe to rewrite as quoted literals.
     * Multi-segment keys: drop bare leaves ("description") to avoid false positives.
     * Single-segment keys: keep as-is (e.g. artwork_name).
     *
     * @return list<string>
     */
    private function rewritableKeyVariants(string $key): array
    {
        $variants = $this->keyVariants($key);
        $segments = preg_split('/[.\\/]/', trim(str_replace('/', '.', $key), '.')) ?: [];
        if (count($segments) <= 1) {
            return array_values(array_unique($variants));
        }

        return array_values(array_filter(
            $variants,
            static fn (string $variant): bool => str_contains($variant, '.') || str_contains($variant, '/'),
        ));
    }

    /**
     * @param  list<string>  $roots
     * @return list<string> relative paths
     */
    private function filesContainingQuotedVariants(array $roots, string $from): array
    {
        $variants = $this->rewritableKeyVariants($from);
        if ($variants === [] || $roots === []) {
            return [];
        }

        $files = [];
        foreach ($this->iterateFiles($roots, ['php', 'js', 'jsx', 'ts', 'tsx', 'vue', 'blade.php']) as $file) {
            $absolute = $file->getPathname();
            $contents = @file_get_contents($absolute);
            if (! is_string($contents) || $contents === '') {
                continue;
            }
            foreach ($variants as $variant) {
                if (
                    str_contains($contents, "'".$variant."'")
                    || str_contains($contents, '"'.$variant.'"')
                    || str_contains($contents, '`'.$variant.'`')
                ) {
                    $files[] = $this->relativePath($absolute);
                    break;
                }
            }
        }

        return $files;
    }

    /**
     * True when usage exists only via open dynamic prefixes (cannot rewrite a single leaf).
     *
     * @param  array<string, list<array{path: string, line: int}>>  $found
     */
    private function isCoveredByOpenDynamicUsage(array $found, string $from): bool
    {
        // Open-prefix evidence is tracked inside the scanner during expand*; rebuild via catalog probe.
        $results = $this->scanner->buildResults([$from], $found);
        $row = $results[0] ?? null;
        if ($row === null || ($row['status'] ?? '') !== 'used') {
            return false;
        }

        // If a concrete quoted/full variant exists in $found, it is not "open-only".
        foreach ($this->rewritableKeyVariants($from) as $variant) {
            if (isset($found[$variant]) && $found[$variant] !== []) {
                return false;
            }
        }
        foreach ($this->usageRowsForKey($found, $from) as $usage) {
            if ($usage !== []) {
                // usageRowsForKey merges normalized helper hits — those ARE rewritable.
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $roots
     * @return array<string, string>
     */
    private function projectMessagePrefixes(array $roots): array
    {
        if ($this->projectMessagePrefixes !== null) {
            return $this->projectMessagePrefixes;
        }

        $map = [];
        foreach ($this->iterateFiles($roots, ['php']) as $file) {
            $contents = @file_get_contents($file->getPathname());
            if (! is_string($contents) || $contents === '') {
                continue;
            }
            foreach ($this->scanner->messageClassPrefixes($contents) as $class => $prefix) {
                $map[(string) $class] = (string) $prefix;
            }
        }

        return $this->projectMessagePrefixes = $map;
    }

    /**
     * Rewrite $homeText('relative.leaf') style calls discovered via usage scan.
     * Same-area: replace the relative leaf. Cross-area: replace the whole call with __().
     */
    private function rewritePrefixedHelperUsages(string $contents, string $from, string $to, int &$count): string
    {
        $wrappers = $this->scanner->translationPrefixWrappers($contents);
        if ($wrappers === []) {
            return $contents;
        }

        $fromNorm = strtolower($this->scanner->normalizeKeyForLexicon($from));
        $toNorm = $this->scanner->normalizeKeyForLexicon($to);
        $updated = $contents;

        foreach ($wrappers as $name => $rawPrefix) {
            $prefixNorm = $this->scanner->normalizeKeyForLexicon(rtrim($rawPrefix, '.'));
            $prefixLower = strtolower($prefixNorm);
            if ($prefixNorm === '' || ! str_starts_with($fromNorm, $prefixLower.'.')) {
                continue;
            }

            $sameArea = str_starts_with(strtolower($toNorm), $prefixLower.'.');
            $toLeaf = $sameArea ? substr($toNorm, strlen($prefixNorm) + 1) : '';
            $toLiteral = $this->laravelHybridKey($toNorm);

            $pattern = '/\$'.preg_quote((string) $name, '/').'\(\s*([\'"])([^\'"]+)\1(\s*(?:,[^)]*)?\s*\))/u';
            $updated = preg_replace_callback(
                $pattern,
                function (array $matches) use (
                    $rawPrefix,
                    $fromNorm,
                    $sameArea,
                    $toLeaf,
                    $toLiteral,
                    $name,
                    &$count,
                ): string {
                    $quote = $matches[1];
                    $leaf = (string) $matches[2];
                    $suffix = (string) $matches[3]; // ", …)" or ")"
                    $callNorm = strtolower($this->scanner->normalizeKeyForLexicon(rtrim($rawPrefix, '.').'.'.$leaf));
                    if ($callNorm !== $fromNorm) {
                        return $matches[0];
                    }

                    $count++;
                    if ($sameArea) {
                        return '$'.$name.'('.$quote.$toLeaf.$quote.$suffix;
                    }

                    return '__('.$quote.$toLiteral.$quote.')';
                },
                $updated,
            ) ?? $updated;
        }

        return $updated;
    }

    /**
     * Rewrite Message::get('leaf') using project-wide Message class → prefix map.
     *
     * @param  array<string, string>  $messagePrefixes
     */
    private function rewriteMessageHelperUsages(
        string $contents,
        string $from,
        string $to,
        int &$count,
        array $messagePrefixes,
    ): string {
        if ($messagePrefixes === []) {
            return $contents;
        }

        $fromNorm = strtolower($this->scanner->normalizeKeyForLexicon($from));
        $toNorm = $this->scanner->normalizeKeyForLexicon($to);
        $updated = $contents;

        foreach ($messagePrefixes as $class => $prefixNorm) {
            $prefixLower = strtolower((string) $prefixNorm);
            if ($prefixNorm === '' || ! str_starts_with($fromNorm, $prefixLower.'.')) {
                continue;
            }

            $sameArea = str_starts_with(strtolower($toNorm), $prefixLower.'.');
            $toLeaf = $sameArea ? substr($toNorm, strlen((string) $prefixNorm) + 1) : '';
            $toLiteral = $this->laravelHybridKey($toNorm);

            $pattern = '/'.preg_quote((string) $class, '/').'::get\\(\\s*([\'"])([^\'"]+)\\1(\\s*(?:,[^)]*)?\\s*\\))/u';
            $updated = preg_replace_callback(
                $pattern,
                function (array $matches) use (
                    $prefixNorm,
                    $fromNorm,
                    $sameArea,
                    $toLeaf,
                    $toLiteral,
                    $class,
                    &$count,
                ): string {
                    $quote = $matches[1];
                    $leaf = (string) $matches[2];
                    $suffix = (string) $matches[3];
                    $callNorm = strtolower(
                        $this->scanner->normalizeKeyForLexicon($prefixNorm.'.'.$leaf)
                    );
                    if ($callNorm !== $fromNorm) {
                        return $matches[0];
                    }

                    $count++;
                    if ($sameArea) {
                        return $class.'::get('.$quote.$toLeaf.$quote.$suffix;
                    }

                    return '__('.$quote.$toLiteral.$quote.')';
                },
                $updated,
            ) ?? $updated;
        }

        return $updated;
    }

    /**
     * Replace remaining quoted full-key literals (arrays, assignments, unusual helpers).
     * Never used for bare single-segment leaves.
     */
    private function replaceQuotedFullKeyLiterals(string $contents, string $from, string $to, int &$count): string
    {
        if ($from === '' || (! str_contains($from, '.') && ! str_contains($from, '/'))) {
            return $contents;
        }

        $pattern = '/([\'"`])'.preg_quote($from, '/').'\1/u';

        return preg_replace_callback(
            $pattern,
            static function (array $matches) use ($to, &$count): string {
                $count++;

                return $matches[1].$to.$matches[1];
            },
            $contents,
        ) ?? $contents;
    }

    private function laravelHybridKey(string $dotted): string
    {
        $parts = explode('.', trim(str_replace('/', '.', $dotted), '.'));
        if (count($parts) <= 1) {
            return implode('.', $parts);
        }

        // area.file.rest → area/file.rest (Laravel group file form)
        return $parts[0].'/'.$parts[1]
            .(count($parts) > 2 ? '.'.implode('.', array_slice($parts, 2)) : '');
    }

    /**
     * @return list<string>
     */
    private function keyVariants(string $key): array
    {
        $variants = [$key];
        if (str_contains($key, '.')) {
            $variants[] = str_replace('.', '/', $key);
            $variants[] = substr($key, (int) strrpos($key, '.') + 1);
            // Laravel lang form: area file path uses /, nested key keeps dots
            // domains.artworks.editor.helper → domains/artworks.editor.helper
            $parts = explode('.', $key);
            for ($i = 1; $i < count($parts); $i++) {
                $variants[] = implode('/', array_slice($parts, 0, $i)).'.'.implode('.', array_slice($parts, $i));
            }
        }
        if (str_contains($key, '/')) {
            $variants[] = str_replace('/', '.', $key);
            $variants[] = substr($key, (int) strrpos($key, '/') + 1);
            // Keep hybrid forms already containing /
            if (str_contains($key, '.')) {
                $variants[] = $key;
            }
        }

        return array_values(array_unique(array_filter($variants)));
    }

    private function alignVariantShape(string $matchedFrom, string $to): string
    {
        $toDotted = str_replace('/', '.', $to);

        // Laravel hybrid: domains/artworks.editor.collection.helper
        if (preg_match('#^((?:[^/]+/)+\w+)\.(.+)$#', $matchedFrom, $matches) === 1) {
            $slashSegments = count(explode('/', $matches[1]));
            $toParts = explode('.', $toDotted);
            if (count($toParts) > $slashSegments) {
                return implode('/', array_slice($toParts, 0, $slashSegments))
                    .'.'
                    .implode('.', array_slice($toParts, $slashSegments));
            }

            return implode('/', $toParts);
        }

        if (! str_contains($matchedFrom, '.') && ! str_contains($matchedFrom, '/')) {
            return str_contains($toDotted, '.')
                ? substr($toDotted, (int) strrpos($toDotted, '.') + 1)
                : $toDotted;
        }

        if (str_contains($matchedFrom, '/') && str_contains($toDotted, '.')) {
            return str_replace('.', '/', $toDotted);
        }

        if (str_contains($matchedFrom, '.') && str_contains($to, '/')) {
            return $toDotted;
        }

        return $toDotted;
    }

    private function replaceExactLiterals(string $contents, string $from, string $to, int &$count): string
    {
        $patterns = [
            "/(__(?:\\s*)\\(\\s*)(['\"])".preg_quote($from, '/')."\\2/u",
            "/(@lang\\(\\s*)(['\"])".preg_quote($from, '/')."\\2/u",
            "/(trans(?:_choice)?\\(\\s*)(['\"])".preg_quote($from, '/')."\\2/u",
            "/(filled_trans\\(\\s*)(['\"])".preg_quote($from, '/')."\\2/u",
            "/(Lang::(?:get|has)\\(\\s*)(['\"])".preg_quote($from, '/')."\\2/u",
            "/(\\bt\\(\\s*)(['\"])".preg_quote($from, '/')."\\2/u",
            "/(\\bi18n\\.t\\(\\s*)(['\"])".preg_quote($from, '/')."\\2/u",
        ];

        $updated = $contents;
        foreach ($patterns as $pattern) {
            $updated = preg_replace_callback($pattern, static function (array $matches) use ($to, &$count): string {
                $count++;

                return $matches[1].$matches[2].$to.$matches[2];
            }, $updated) ?? $updated;
        }

        return $updated;
    }

    /**
     * @param  list<string>  $roots
     * @param  list<string>  $extensions
     * @return list<SplFileInfo>
     */
    private function iterateFiles(array $roots, array $extensions): array
    {
        $files = [];
        foreach ($roots as $root) {
            $root = rtrim($root, DIRECTORY_SEPARATOR);
            if ($root === '' || ! is_dir($root)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $ext = strtolower($file->getExtension());
                if (! in_array($ext, $extensions, true)) {
                    continue;
                }
                $normalized = str_replace('\\', '/', $file->getPathname());
                if (str_contains($normalized, '/vendor/') || str_contains($normalized, '/node_modules/')) {
                    continue;
                }
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @param  list<string>  $roots
     */
    private function absoluteFromRelative(string $relative, array $roots): ?string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $base = base_path($relative);
        if (is_file($base)) {
            return $base;
        }

        foreach ($roots as $root) {
            $candidate = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function relativePath(string $path): string
    {
        $base = str_replace('\\', '/', base_path());
        $normalized = str_replace('\\', '/', $path);
        if (str_starts_with($normalized, $base.'/')) {
            return substr($normalized, strlen($base) + 1);
        }

        return $normalized;
    }
}
