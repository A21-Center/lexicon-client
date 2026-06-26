<?php

namespace A21\LexiconClient\Files;

use Illuminate\Support\Facades\File;

class LexiconFileWriter
{
    /**
     * @param  list<array<string, mixed>>  $files
     * @return list<string>
     */
    public function write(array $files, array $outputConfig, bool $dryRun = false, bool $force = false): array
    {
        $basePath = $this->resolveBasePath($outputConfig);
        $pattern = (string) ($outputConfig['pattern'] ?? '{locale}/{area}.json');
        $written = [];

        foreach ($files as $file) {
            $relativePath = str_replace(
                ['{locale}', '{area}'],
                [(string) $file['language'], (string) $file['area']],
                $pattern,
            );

            $absolutePath = $basePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $encoded = json_encode(
                $file['content'] ?? [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            if (! $force && is_file($absolutePath)) {
                $existingHash = hash('sha256', (string) file_get_contents($absolutePath));
                if ($existingHash === ($file['hash'] ?? null)) {
                    continue;
                }
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
