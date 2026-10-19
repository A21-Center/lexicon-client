<?php

namespace A21\LexiconClient\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Exact-literal key usage scanner for Blade/PHP/JS/TS/Vue files.
 * Dynamic concatenations are ignored by design (V1).
 */
class KeyUsageScanner
{
    /** @var list<string> */
    private const EXTENSIONS = ['php', 'blade.php', 'js', 'jsx', 'ts', 'tsx', 'vue'];

    /**
     * @param  list<string>  $roots
     * @return array<string, list<array{path: string, line: int}>>
     */
    public function scan(array $roots): array
    {
        $found = [];

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

                $path = $file->getPathname();
                if (! $this->isScannable($path) || $this->shouldSkip($path)) {
                    continue;
                }

                $relative = $this->relativePath($path);
                $contents = @file_get_contents($path);
                if (! is_string($contents) || $contents === '') {
                    continue;
                }

                foreach ($this->extractLiterals($contents) as $match) {
                    $key = $match['key'];
                    $found[$key] ??= [];
                    $found[$key][] = [
                        'path' => $relative,
                        'line' => $match['line'],
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $catalogKeys
     * @param  array<string, list<array{path: string, line: int}>>  $foundInCode
     * @return list<array{full_key: string, status: string, evidence: list<array{path: string, line: int}>}>
     */
    public function buildResults(array $catalogKeys, array $foundInCode): array
    {
        $index = $this->buildNormalizedEvidenceIndex($foundInCode);

        $results = [];
        foreach ($catalogKeys as $fullKey) {
            $key = trim((string) $fullKey);
            if ($key === '') {
                continue;
            }

            $evidence = $index[$key] ?? $foundInCode[$key] ?? [];
            $results[] = [
                'full_key' => $key,
                'status' => $evidence !== [] ? 'used' : 'unused',
                'evidence' => array_values($evidence),
            ];
        }

        return $results;
    }

    /**
     * Normalize client literals toward Lexicon full_key form.
     * Examples:
     * - api/studio/artwork.x → studio.artwork.x
     * - emails/gallery/account/new-billing-address.intro → gallery.account.new_billing_address.intro
     */
    public function normalizeKeyForLexicon(string $key): string
    {
        $normalized = str_replace(['/', '-'], ['.', '_'], trim($key));
        foreach (['emails.', 'api.'] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
            }
        }

        return $normalized;
    }

    /**
     * Expand scan hits with Message::get('leaf') resolved against __('prefix.'.$key) helpers.
     *
     * @param  list<string>  $roots
     * @param  array<string, list<array{path: string, line: int}>>  $foundInCode
     * @return array<string, list<array{path: string, line: int}>>
     */
    public function expandMessageHelperUsage(array $roots, array $foundInCode): array
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
                if (! $file->isFile() || ! str_ends_with(strtolower($file->getFilename()), '.php')) {
                    continue;
                }
                $path = $file->getPathname();
                if ($this->shouldSkip($path)) {
                    continue;
                }
                $files[] = $path;
            }
        }

        $classToPrefix = [];
        foreach ($files as $path) {
            $contents = @file_get_contents($path);
            if (! is_string($contents) || $contents === '') {
                continue;
            }
            if (! preg_match('/class\\s+(\\w+Message)\\b/', $contents, $classMatch)) {
                continue;
            }
            if (! preg_match("/__\\(\\s*['\"]([^'\"]+)['\"]\\s*\\.\\s*\\\$key/", $contents, $prefixMatch)) {
                continue;
            }
            $classToPrefix[$classMatch[1]] = $this->normalizeKeyForLexicon(rtrim($prefixMatch[1], '.'));
        }

        if ($classToPrefix === []) {
            return $foundInCode;
        }

        $expanded = $foundInCode;
        foreach ($files as $path) {
            $contents = @file_get_contents($path);
            if (! is_string($contents) || $contents === '') {
                continue;
            }
            $relative = $this->relativePath($path);
            if (! preg_match_all(
                "/(\\w+Message)::get\\(\\s*['\"]([^'\"]+)['\"]/u",
                $contents,
                $calls,
                PREG_OFFSET_CAPTURE,
            )) {
                continue;
            }

            foreach ($calls[1] as $idx => $classHit) {
                $class = (string) $classHit[0];
                $leaf = trim((string) $calls[2][$idx][0]);
                $prefix = $classToPrefix[$class] ?? null;
                if ($prefix === null || $leaf === '' || str_contains($leaf, '$') || str_contains($leaf, '{')) {
                    continue;
                }
                $full = $prefix.'.'.$this->normalizeKeyForLexicon($leaf);
                $offset = (int) $calls[2][$idx][1];
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $expanded[$full] ??= [];
                $expanded[$full][] = ['path' => $relative, 'line' => $line];
            }
        }

        return $expanded;
    }

    /**
     * @param  array<string, list<array{path: string, line: int}>>  $foundInCode
     * @return array<string, list<array{path: string, line: int}>>
     */
    private function buildNormalizedEvidenceIndex(array $foundInCode): array
    {
        $index = [];
        foreach ($foundInCode as $rawKey => $evidence) {
            $variants = array_values(array_unique(array_filter([
                (string) $rawKey,
                $this->normalizeKeyForLexicon((string) $rawKey),
                str_replace('/', '.', (string) $rawKey),
            ])));
            foreach ($variants as $variant) {
                $index[$variant] = array_values(array_merge($index[$variant] ?? [], $evidence));
            }
        }

        return $index;
    }

    /**
     * Collect exact keys from nested PHP/JSON translation arrays.
     *
     * For Laravel PHP group files, pass $groupPrefix (e.g. "root/auth") so keys
     * match runtime calls like __('root/auth.sign_up.step.email.title').
     *
     * @param  list<string>  $files
     * @param  array<string, string>  $groupPrefixes  map absolute file path => group prefix
     * @return list<string>
     */
    public function collectKeysFromTranslationFiles(array $files, array $groupPrefixes = []): array
    {
        $keys = [];

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $prefix = $groupPrefixes[$file] ?? $groupPrefixes[realpath($file) ?: $file] ?? '';

            if ($extension === 'json') {
                $decoded = json_decode((string) file_get_contents($file), true);
                if (is_array($decoded)) {
                    $this->flattenKeys($decoded, $prefix, $keys);
                }

                continue;
            }

            if ($extension === 'php') {
                $data = include $file;
                if (is_array($data)) {
                    $this->flattenKeys($data, $prefix, $keys);
                }
            }
        }

        $keys = array_values(array_unique(array_filter($keys, static fn ($key) => is_string($key) && $key !== '')));
        sort($keys);

        return $keys;
    }

    /**
     * @return list<array{key: string, line: int}>
     */
    private function extractLiterals(string $contents): array
    {
        $matches = [];
        $patterns = [
            // PHP / Blade
            "/__(?:\\s*)\\(\\s*['\"]([^'\"]+)['\"]/u",
            "/@lang\\(\\s*['\"]([^'\"]+)['\"]/u",
            "/trans(?:_choice)?\\(\\s*['\"]([^'\"]+)['\"]/u",
            "/Lang::(?:get|has)\\(\\s*['\"]([^'\"]+)['\"]/u",
            // JS / TS helpers
            "/\\bt\\(\\s*['\"]([^'\"]+)['\"]/u",
            "/\\bi18n\\.t\\(\\s*['\"]([^'\"]+)['\"]/u",
            "/\\buseTranslation\\([^)]*\\)[^;]*\\.t\\(\\s*['\"]([^'\"]+)['\"]/u",
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $contents, $groups, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($groups[1] as $group) {
                $key = trim((string) $group[0]);
                if ($key === '' || str_contains($key, '$') || str_contains($key, '{') || str_ends_with($key, '.')) {
                    continue;
                }
                $offset = (int) $group[1];
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $matches[] = ['key' => $key, 'line' => $line];
            }
        }

        return $matches;
    }

    /**
     * @param  array<string|int, mixed>  $data
     * @param  list<string>  $keys
     */
    private function flattenKeys(array $data, string $prefix, array &$keys): void
    {
        foreach ($data as $segment => $value) {
            $next = $prefix === '' ? (string) $segment : $prefix.'.'.$segment;
            if (is_array($value)) {
                $this->flattenKeys($value, $next, $keys);

                continue;
            }
            $keys[] = $next;
        }
    }

    private function isScannable(string $path): bool
    {
        $lower = strtolower($path);
        foreach (self::EXTENSIONS as $extension) {
            if (str_ends_with($lower, '.'.$extension)) {
                return true;
            }
        }

        return false;
    }

    private function shouldSkip(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return str_contains($normalized, '/vendor/')
            || str_contains($normalized, '/node_modules/')
            || str_contains($normalized, '/storage/')
            || str_contains($normalized, '/.git/');
    }

    private function relativePath(string $path): string
    {
        $base = base_path();
        $normalized = str_replace('\\', '/', $path);
        $baseNormalized = str_replace('\\', '/', $base);

        if (str_starts_with($normalized, $baseNormalized.'/')) {
            return substr($normalized, strlen($baseNormalized) + 1);
        }

        return $normalized;
    }
}
