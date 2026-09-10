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
        $results = [];
        foreach ($catalogKeys as $fullKey) {
            $key = trim((string) $fullKey);
            if ($key === '') {
                continue;
            }

            $evidence = $foundInCode[$key] ?? [];
            $results[] = [
                'full_key' => $key,
                'status' => $evidence !== [] ? 'used' : 'unused',
                'evidence' => array_values($evidence),
            ];
        }

        return $results;
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
