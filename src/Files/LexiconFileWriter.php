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
            $encoded = $this->encodeContent($content, $format);
            $contentHash = $this->contentHash($file, $content);

            if ($baseline) {
                if (is_file($absolutePath) && $this->isUnchanged($absolutePath, $content, $encoded, $file, $format)) {
                    $nextHashes[$stateKey] = $contentHash;
                }
                $skipped++;
                continue;
            }

            if (! $force && $this->shouldSkip(
                $absolutePath,
                $content,
                $encoded,
                $file,
                $format,
                $knownHashes[$stateKey] ?? null,
                $contentHash,
                $allowWithoutState,
            )) {
                $skipped++;
                // Only record hash as applied when we truly skip because Lexicon is
                // unchanged AND local already matches (or seed without area scope).
                if ($previous = ($knownHashes[$stateKey] ?? null)) {
                    if ($previous === $contentHash) {
                        $nextHashes[$stateKey] = $contentHash;
                    }
                } elseif (! $allowWithoutState) {
                    $nextHashes[$stateKey] = $contentHash;
                }
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
        string $encoded,
        array $file,
        string $format,
        ?string $previousHash,
        string $contentHash,
        bool $allowWithoutState,
    ): bool {
        $localMatches = is_file($absolutePath)
            && $this->isUnchanged($absolutePath, $content, $encoded, $file, $format);

        // --area / --full / --force scope: catch up local when it lags Lexicon,
        // even if the Lexicon hash was already saved (common after git restore).
        if ($allowWithoutState) {
            return $localMatches;
        }

        // Daily pull: rewrite only when Lexicon content hash changed.
        if ($previousHash !== null && $previousHash === $contentHash) {
            return true;
        }

        if ($previousHash !== null && $previousHash !== $contentHash) {
            return false;
        }

        // No prior state: seed hash only (caller stores it), do not mass-write.
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
    private function encodeContent(array $content, string $format): string
    {
        if ($format === 'php') {
            return $this->phpEncoder->encode($content);
        }

        return (string) json_encode(
            $content,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $file
     */
    private function isUnchanged(
        string $absolutePath,
        array $content,
        string $encoded,
        array $file,
        string $format,
    ): bool {
        if ($format === 'php') {
            try {
                return $this->normalize($this->phpParser->parse($absolutePath)) === $this->normalize($content);
            } catch (\Throwable) {
                return false;
            }
        }

        $existing = json_decode((string) file_get_contents($absolutePath), true);
        if (is_array($existing)) {
            return $this->normalize($existing) === $this->normalize($content);
        }

        $existingHash = hash('sha256', (string) file_get_contents($absolutePath));

        if (isset($file['hash']) && is_string($file['hash']) && $file['hash'] !== '') {
            return $existingHash === $file['hash'];
        }

        return $existingHash === hash('sha256', $encoded);
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
