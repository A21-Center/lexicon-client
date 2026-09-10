<?php

namespace A21\LexiconClient\Extraction\Extractors;

use A21\LexiconClient\Extraction\ExtractedEntry;
use A21\LexiconClient\Extraction\Extractor;
use A21\LexiconClient\Import\AreaCodeResolver;
use A21\LexiconClient\Import\JsonTranslationParser;
use A21\LexiconClient\Import\PhpTranslationParser;
use A21\LexiconClient\Import\TranslationFileScanner;

/**
 * Extracts interface strings from lang files (php/json) for the source locale.
 *
 * Definition keys: base_path (absolute), source_language, formats, application,
 * module, layer (default interface). Emits one entry per flattened key.
 */
class FilesExtractor implements Extractor
{
    public function __construct(
        private readonly TranslationFileScanner $scanner = new TranslationFileScanner(),
        private readonly PhpTranslationParser $phpParser = new PhpTranslationParser(),
        private readonly JsonTranslationParser $jsonParser = new JsonTranslationParser(),
        private readonly AreaCodeResolver $areaCodes = new AreaCodeResolver(),
    ) {}

    public function type(): string
    {
        return 'files';
    }

    public function extract(array $definition): array
    {
        $basePath = (string) ($definition['base_path'] ?? 'lang');
        $sourceLanguage = (string) ($definition['source_language'] ?? 'en');
        $formats = array_values(array_map('strval', (array) ($definition['formats'] ?? ['php', 'json'])));
        $layer = isset($definition['layer']) ? (string) $definition['layer'] : 'interface';
        $application = (string) ($definition['application'] ?? 'app');
        $module = (string) ($definition['module'] ?? 'interface');

        $files = $this->scanner->scan(
            basePath: $basePath,
            formats: $formats,
            localesFilter: [$sourceLanguage],
        );

        $entries = [];

        foreach ($files as $file) {
            $data = $file['format'] === 'json'
                ? $this->jsonParser->parse($file['absolute_path'])
                : $this->phpParser->parse($file['absolute_path']);

            foreach ($this->flatten($data) as $keyPath => $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }

                $entries[] = new ExtractedEntry(
                    areaCode: $file['area_code'],
                    application: $application,
                    module: $module,
                    entityType: 'translation_file',
                    entityId: $this->areaCodes->fromRelativePath($file['relative_path']).'::'.$keyPath,
                    fieldName: $keyPath,
                    sourceText: $value,
                    layer: $layer,
                    sourceLanguage: $sourceLanguage,
                    metadata: [
                        'locale' => $file['locale'],
                        'relative_path' => $file['relative_path'],
                        'key' => $keyPath,
                    ],
                );
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $compound = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $compound);

                continue;
            }

            $flat[$compound] = $value;
        }

        return $flat;
    }
}
