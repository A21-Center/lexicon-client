<?php

namespace A21\LexiconClient\Import;

use RuntimeException;

class TranslationImportPayloadBuilder
{
    public function __construct(
        private readonly PhpTranslationParser $phpParser = new PhpTranslationParser(),
        private readonly JsonTranslationParser $jsonParser = new JsonTranslationParser(),
    ) {}

    /**
     * @param  list<array{
     *   locale: string,
     *   absolute_path: string,
     *   relative_path: string,
     *   area_code: string,
     *   source_path_pattern: string,
     *   format: string
     * }>  $files
     * @param  array<string, mixed>  $config
     * @return array{
     *   payload: array<string, mixed>,
     *   stats: array<string, int|list<string>>,
     *   warnings: list<array<string, mixed>>
     * }
     */
    public function build(
        array $files,
        array $config,
        string $strategy,
        string $formatOption,
        string $basePath,
    ): array {
        $payloadFiles = [];
        $warnings = [];
        $areas = [];
        $sources = [];
        $keyPaths = [];
        $locales = [];
        $translationsCount = 0;
        $detectedFormat = $formatOption === 'auto' ? null : $formatOption;

        foreach ($files as $file) {
            $locales[$file['locale']] = true;
            $areas[$file['area_code']] = true;
            $sources[$file['source_path_pattern']] = true;

            try {
                $content = match ($file['format']) {
                    'php' => $this->phpParser->parse($file['absolute_path']),
                    'json' => $this->jsonParser->parse($file['absolute_path']),
                    default => throw new RuntimeException('Unsupported format: '.$file['format']),
                };
            } catch (\Throwable $exception) {
                $warnings[] = [
                    'type' => 'invalid_file_format',
                    'area_code' => $file['area_code'],
                    'locale' => $file['locale'],
                    'message' => $exception->getMessage(),
                ];

                continue;
            }

            if ($content === []) {
                $warnings[] = [
                    'type' => 'empty_file',
                    'area_code' => $file['area_code'],
                    'locale' => $file['locale'],
                    'message' => 'Empty translation file',
                ];
            }

            $flat = $this->flatten($content);
            foreach (array_keys($flat) as $keyPath) {
                $keyPaths[$file['area_code'].'.'.$keyPath] = true;
            }
            $translationsCount += count(array_filter($flat, fn ($value) => is_string($value) && $value !== ''));

            $detectedFormat ??= $file['format'];

            $payloadFiles[] = [
                'locale' => $file['locale'],
                'relative_path' => $file['relative_path'],
                'area_code' => $file['area_code'],
                'source_path_pattern' => $file['source_path_pattern'],
                'content' => $content,
            ];
        }

        $importConfig = (array) ($config['import'] ?? []);

        return [
            'payload' => [
                'project_code' => $config['project_code'],
                'environment' => $config['environment'],
                'source_language' => (string) ($importConfig['source_language'] ?? 'en'),
                'format' => $detectedFormat ?? 'php',
                'strategy' => $strategy,
                'base_path' => $basePath,
                'files' => $payloadFiles,
            ],
            'stats' => [
                'files' => count($payloadFiles),
                'locales' => array_keys($locales),
                'areas' => array_keys($areas),
                'sources' => count($sources),
                'keys' => count($keyPaths),
                'translations' => $translationsCount,
                'warnings' => count($warnings),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, string|null>
     */
    private function flatten(array $content, string $prefix = ''): array
    {
        $entries = [];

        foreach ($content as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $entries = [...$entries, ...$this->flatten($value, $path)];

                continue;
            }

            if (is_bool($value)) {
                $entries[$path] = $value ? 'true' : 'false';
            } elseif (is_int($value) || is_float($value)) {
                $entries[$path] = (string) $value;
            } elseif ($value === null) {
                $entries[$path] = null;
            } elseif (is_string($value)) {
                $entries[$path] = $value;
            }
        }

        return $entries;
    }
}
