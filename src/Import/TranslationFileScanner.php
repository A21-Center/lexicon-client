<?php

namespace A21\LexiconClient\Import;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use RuntimeException;
use SplFileInfo;

class TranslationFileScanner
{
    public function __construct(
        private readonly AreaCodeResolver $areaCodes = new AreaCodeResolver(),
    ) {}

    /**
     * @param  list<string>  $formats
     * @param  list<string>  $exclude
     * @param  list<string>|null  $localesFilter
     * @param  list<string>|null  $areasFilter
     * @return list<array{
     *   locale: string,
     *   absolute_path: string,
     *   relative_path: string,
     *   area_code: string,
     *   source_path_pattern: string,
     *   format: string
     * }>
     */
    public function scan(
        string $basePath,
        array $formats = ['php', 'json'],
        array $exclude = ['vendor', 'node_modules', '.git'],
        ?array $localesFilter = null,
        ?array $areasFilter = null,
    ): array {
        $root = rtrim($basePath, '/\\');

        if (! is_dir($root)) {
            throw new RuntimeException("Import base path does not exist: {$root}");
        }

        $extensions = array_map('strtolower', $formats);
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $absolute = $file->getPathname();
            $relativeFromRoot = ltrim(str_replace('\\', '/', substr($absolute, strlen($root))), '/');

            if ($this->isExcluded($relativeFromRoot, $exclude)) {
                continue;
            }

            $extension = strtolower($file->getExtension());
            if (! in_array($extension, $extensions, true)) {
                continue;
            }

            $parts = explode('/', $relativeFromRoot);
            if (count($parts) < 2) {
                continue;
            }

            $locale = $parts[0];
            if (! $this->looksLikeLocale($locale)) {
                continue;
            }

            if ($localesFilter !== null && $localesFilter !== [] && ! in_array($locale, $localesFilter, true)) {
                continue;
            }

            $relativePath = implode('/', array_slice($parts, 1));
            $areaCode = $this->areaCodes->fromRelativePath($relativePath);

            if ($areasFilter !== null && $areasFilter !== [] && ! in_array($areaCode, $areasFilter, true)) {
                continue;
            }

            $baseForPattern = trim(str_replace('\\', '/', $basePath), '/');
            // Prefer last segment when absolute path is passed (…/lang)
            if (str_contains($baseForPattern, '/') && is_dir($basePath)) {
                $baseForPattern = basename($baseForPattern);
            }

            $files[] = [
                'locale' => $locale,
                'absolute_path' => $absolute,
                'relative_path' => $relativePath,
                'area_code' => $areaCode,
                'source_path_pattern' => $this->areaCodes->sourcePathPattern($baseForPattern ?: 'lang', $relativePath),
                'format' => $extension,
            ];
        }

        usort($files, fn (array $a, array $b) => [$a['locale'], $a['relative_path']] <=> [$b['locale'], $b['relative_path']]);

        return $files;
    }

    /**
     * @param  list<string>  $exclude
     */
    private function isExcluded(string $relativePath, array $exclude): bool
    {
        foreach ($exclude as $segment) {
            if ($segment !== '' && str_contains('/'.$relativePath.'/', '/'.$segment.'/')) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeLocale(string $value): bool
    {
        return (bool) preg_match('/^[a-z]{2,3}([_-][A-Za-z0-9]+)?$/', $value);
    }
}
