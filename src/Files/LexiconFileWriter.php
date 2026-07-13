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
    ) {}

    /**
     * @param  list<array<string, mixed>>  $files
     * @param  array<string, mixed>  $outputConfig
     * @return list<string>
     */
    public function write(array $files, array $outputConfig, bool $dryRun = false, bool $force = false): array
    {
        $basePath = $this->resolveBasePath($outputConfig);
        $format = $this->resolveWriterFormat($outputConfig);
        $pattern = (string) ($outputConfig['pattern'] ?? $this->defaultPattern($format));
        $written = [];

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
            $encoded = $this->encodeContent($file['content'] ?? [], $format);
            $content = is_array($file['content'] ?? null) ? $file['content'] : [];

            if (! $force && is_file($absolutePath) && $this->isUnchanged($absolutePath, $content, $encoded, $file, $format)) {
                continue;
            }

            if ($dryRun) {
                $written[] = $absolutePath;
                continue;
            }

            File::ensureDirectoryExists(dirname($absolutePath));
            File::put($absolutePath, $encoded);
            $written[] = $absolutePath;
        }

        return $written;
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
                return $this->phpParser->parse($absolutePath) === $content;
            } catch (\Throwable) {
                return false;
            }
        }

        $existingHash = hash('sha256', (string) file_get_contents($absolutePath));

        if (isset($file['hash']) && is_string($file['hash']) && $file['hash'] !== '') {
            return $existingHash === $file['hash'];
        }

        return $existingHash === hash('sha256', $encoded);
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
