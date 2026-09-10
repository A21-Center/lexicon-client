<?php

namespace A21\LexiconClient\Extraction\Extractors;

use A21\LexiconClient\Extraction\ExtractedEntry;
use A21\LexiconClient\Extraction\Extractor;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Extracts template strings from Blade files via translation helper calls
 * (__('...'), trans('...'), @lang('...')).
 *
 * Definition keys: paths (list of absolute dirs), application, module, area,
 * layer (default template).
 */
class BladeExtractor implements Extractor
{
    private const HELPER_PATTERN = '/(?:__|trans|@lang)\(\s*([\'"])(.+?)\1/s';

    public function type(): string
    {
        return 'blade';
    }

    public function extract(array $definition): array
    {
        $paths = array_values(array_map('strval', (array) ($definition['paths'] ?? [])));
        $layer = isset($definition['layer']) ? (string) $definition['layer'] : 'template';
        $application = (string) ($definition['application'] ?? 'app');
        $module = (string) ($definition['module'] ?? 'templates');
        $area = (string) ($definition['area'] ?? 'templates');

        $entries = [];
        $seen = [];

        foreach ($paths as $path) {
            foreach ($this->bladeFiles($path) as $file) {
                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($path, '/\\')))), '/');
                $contents = (string) file_get_contents($file->getPathname());

                if (! preg_match_all(self::HELPER_PATTERN, $contents, $matches)) {
                    continue;
                }

                foreach ($matches[2] as $string) {
                    $string = trim($string);

                    if ($string === '') {
                        continue;
                    }

                    $dedupeKey = $relative.'|'.$string;

                    if (isset($seen[$dedupeKey])) {
                        continue;
                    }

                    $seen[$dedupeKey] = true;

                    $entries[] = new ExtractedEntry(
                        areaCode: $area,
                        application: $application,
                        module: $module,
                        entityType: 'template',
                        entityId: $relative,
                        fieldName: $this->slug($string),
                        sourceText: $string,
                        layer: $layer,
                        metadata: [
                            'template' => $relative,
                            'segment' => $this->slug($string),
                        ],
                    );
                }
            }
        }

        return $entries;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function bladeFiles(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with(strtolower($file->getFilename()), '.blade.php')) {
                $files[] = $file;
            }
        }

        usort($files, fn (SplFileInfo $a, SplFileInfo $b): int => $a->getPathname() <=> $b->getPathname());

        return $files;
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $value));
        $slug = trim($slug, '_');
        $slug = substr($slug, 0, 60);

        return trim($slug, '_') ?: 'segment';
    }
}
