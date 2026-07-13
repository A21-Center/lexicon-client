<?php

namespace A21\LexiconClient\Files;

use A21\LexiconClient\Import\PhpTranslationParser;
use Illuminate\Support\Facades\File;

class LexiconFileWriter
{
    public function __construct(
        private readonly PhpArrayEncoder $phpEncoder = new PhpArrayEncoder(),
        private readonly RelativePathResolver $relativePaths = new RelativePathResolver(),
        private readonly PhpTranslationParser $phpParser = new PhpTranslationParser(),
        private readonly PhpArraySoftMerger $softMerger = new PhpArraySoftMerger(),
        private readonly PhpArrayFilePatcher $phpPatcher = new PhpArrayFilePatcher(),
        private readonly ?PullStateStore $pullState = null,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $files
     * @param  array<string, mixed>  $outputConfig
     * @return array{written: list<string>, skipped: int}
     */
    public function write(
        array $files,
        array $outputConfig,
        bool $dryRun = false,
        bool $force = false,
        bool $baseline = false,
        bool $allowWithoutState = false,
    ): array {
        $basePath = $this->resolveBasePath($outputConfig);
        $format = $this->resolveWriterFormat($outputConfig);
        $merge = $this->resolveMergeMode($outputConfig, $format);
        $pattern = (string) ($outputConfig['pattern'] ?? $this->defaultPattern($format));
        $state = $this->pullState ?? PullStateStore::defaultPath();
        $knownHashes = $force || $baseline ? [] : $state->hashes();
        $nextHashes = $baseline ? [] : $knownHashes;
        $written = [];
        $skipped = 0;

        foreach ($files as $file) {
            $relativePath = $this->resolveRelativePath($file, $format);
            $path = str_replace(
                ['{locale}', '{area}', '{relative_path}', '{path}'],
                [
                    (string) ($file['language'] ?? ''),
                    (string) ($file['area'] ?? ''),
                    $relativePath,
                    (string) ($file['path'] ?? $relativePath),
                ],
                $pattern,
            );

            $absolutePath = $basePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
            $stateKey = str_replace('\\', '/', $path);
            $content = is_array($file['content'] ?? null) ? $file['content'] : [];
            $contentHash = $this->contentHash($file, $content);

            if ($baseline) {
                if (is_file($absolutePath) && $this->isUnchanged($absolutePath, $content, $file, $format, $merge)) {
                    $nextHashes[$stateKey] = $contentHash;
                }
                $skipped++;
                continue;
            }

            if (! $force && $this->shouldSkip(
                $absolutePath,
                $content,
                $file,
                $format,
                $merge,
                $knownHashes[$stateKey] ?? null,
                $contentHash,
                $allowWithoutState,
            )) {
                $skipped++;
                if ($previous = ($knownHashes[$stateKey] ?? null)) {
                    if ($previous === $contentHash) {
                        $nextHashes[$stateKey] = $contentHash;
                    }
                } elseif (! $allowWithoutState) {
                    $nextHashes[$stateKey] = $contentHash;
                }
                continue;
            }

            $encoded = $this->prepareEncodedContent($absolutePath, $content, $format, $merge);

            // Soft merge may no-op (e.g. Lexicon value-only change with add_missing).
            if (! $force && is_file($absolutePath) && (string) file_get_contents($absolutePath) === $encoded) {
                $skipped++;
                $nextHashes[$stateKey] = $contentHash;
                continue;
            }

            if ($dryRun) {
                $written[] = $absolutePath;
                $nextHashes[$stateKey] = $contentHash;
                continue;
            }

            File::ensureDirectoryExists(dirname($absolutePath));
            File::put($absolutePath, $encoded);
            $written[] = $absolutePath;
            $nextHashes[$stateKey] = $contentHash;
        }

        if (! $dryRun) {
            $state->save($nextHashes);
        }

        return [
            'written' => $written,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $file
     */
    private function shouldSkip(
        string $absolutePath,
        array $content,
        array $file,
        string $format,
        string $merge,
        ?string $previousHash,
        string $contentHash,
        bool $allowWithoutState,
    ): bool {
        $localMatches = is_file($absolutePath)
            && $this->isUnchanged($absolutePath, $content, $file, $format, $merge);

        if ($allowWithoutState) {
            return $localMatches;
        }

        if ($previousHash !== null && $previousHash === $contentHash) {
            return true;
        }

        if ($previousHash !== null && $previousHash !== $contentHash) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $file
     * @param  array<string, mixed>  $content
     */
    private function contentHash(array $file, array $content): string
    {
        if (isset($file['hash']) && is_string($file['hash']) && $file['hash'] !== '') {
            return $file['hash'];
        }

        return hash('sha256', (string) json_encode($this->normalize($content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private function resolveRelativePath(array $file, string $format): string
    {
        $extension = $format === 'php' ? 'php' : 'json';

        if (! empty($file['relative_path']) && is_string($file['relative_path'])) {
            return $this->relativePaths->fromArea(
                (string) ($file['area'] ?? 'translations'),
                $file['relative_path'],
                $extension,
            );
        }

        return $this->relativePaths->fromArea(
            (string) ($file['area'] ?? 'translations'),
            null,
            $extension,
        );
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function prepareEncodedContent(string $absolutePath, array $content, string $format, string $merge): string
    {
        if ($format === 'php') {
            return $this->encodePhpSoft($absolutePath, $content, $merge);
        }

        return (string) json_encode(
            $content,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function encodePhpSoft(string $absolutePath, array $content, string $merge): string
    {
        if (! is_file($absolutePath)) {
            return $this->phpEncoder->encode($content);
        }

        try {
            $existing = $this->phpParser->parse($absolutePath);
        } catch (\Throwable) {
            return $this->phpEncoder->encode($content);
        }

        $source = (string) file_get_contents($absolutePath);

        if ($merge === 'add_missing') {
            $missing = $this->softMerger->missingLeavesWithExistingParents($existing, $content);
            if ($missing === []) {
                return $source;
            }

            $patched = $this->phpPatcher->injectMissingLeaves($source, $missing);
            if ($patched !== null) {
                return $patched;
            }

            $merged = $existing;
            foreach ($missing as $path => $value) {
                $merged = $this->setLeaf($merged, (string) $path, $value);
            }

            return $this->phpEncoder->encode($merged);
        }

        $merged = $this->softMerger->replace($existing, $content);

        return $this->phpEncoder->encode($merged);
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $file
     */
    private function isUnchanged(
        string $absolutePath,
        array $content,
        array $file,
        string $format,
        string $merge,
    ): bool {
        if ($format === 'php') {
            try {
                $existing = $this->phpParser->parse($absolutePath);
            } catch (\Throwable) {
                return false;
            }

            if ($merge === 'add_missing') {
                return $this->softMerger->missingLeavesWithExistingParents($existing, $content) === [];
            }

            return $this->normalize($existing) === $this->normalize($content);
        }

        $existing = json_decode((string) file_get_contents($absolutePath), true);
        if (is_array($existing)) {
            return $this->normalize($existing) === $this->normalize($content);
        }

        $existingHash = hash('sha256', (string) file_get_contents($absolutePath));

        if (isset($file['hash']) && is_string($file['hash']) && $file['hash'] !== '') {
            return $existingHash === $file['hash'];
        }

        $encoded = (string) json_encode(
            $content,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $existingHash === hash('sha256', $encoded);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function setLeaf(array $content, string $path, mixed $value): array
    {
        $keys = explode('.', $path);
        $cursor = &$content;

        foreach ($keys as $index => $key) {
            if ($index === count($keys) - 1) {
                $cursor[$key] = $value;
                break;
            }

            if (! isset($cursor[$key]) || ! is_array($cursor[$key])) {
                $cursor[$key] = [];
            }

            $cursor = &$cursor[$key];
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function normalize(array $content): array
    {
        $normalized = [];

        foreach ($content as $key => $value) {
            $stringKey = (string) $key;

            if (is_array($value)) {
                $normalized[$stringKey] = $this->normalize($value);
                continue;
            }

            if (is_bool($value)) {
                $normalized[$stringKey] = $value ? 'true' : 'false';
            } elseif (is_int($value) || is_float($value)) {
                $normalized[$stringKey] = (string) $value;
            } elseif ($value === null) {
                $normalized[$stringKey] = null;
            } else {
                $normalized[$stringKey] = (string) $value;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $outputConfig
     */
    private function resolveWriterFormat(array $outputConfig): string
    {
        $format = strtolower((string) ($outputConfig['format'] ?? 'nested_json'));

        return match ($format) {
            'php', 'laravel_php' => 'php',
            'json', 'nested_json', 'flat_json' => 'json',
            default => 'json',
        };
    }

    /**
     * @param  array<string, mixed>  $outputConfig
     */
    private function resolveMergeMode(array $outputConfig, string $format): string
    {
        $merge = strtolower((string) ($outputConfig['merge'] ?? ''));

        if (in_array($merge, ['add_missing', 'replace'], true)) {
            return $merge;
        }

        // PHP lang files are usually curated in-repo: never mass-rewrite formatting.
        return $format === 'php' ? 'add_missing' : 'replace';
    }

    private function defaultPattern(string $format): string
    {
        return $format === 'php'
            ? '{locale}/{relative_path}'
            : '{locale}/{area}.json';
    }

    /**
     * @param  array<string, mixed>  $outputConfig
     */
    private function resolveBasePath(array $outputConfig): string
    {
        $configured = (string) ($outputConfig['base_path'] ?? public_path('locales'));

        if (str_starts_with($configured, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $configured) === 1) {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }

        return rtrim(base_path($configured), DIRECTORY_SEPARATOR);
    }
}
