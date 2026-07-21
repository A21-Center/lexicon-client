<?php

namespace A21\LexiconClient\Manifest;

use Illuminate\Support\Arr;

class LexiconManifestReader
{
    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        $manifestPath = (string) config('lexicon.manifest', base_path('lexicon.json'));

        if (! is_file($manifestPath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function mergedConfig(): array
    {
        $manifest = $this->read();

        return [
            'api_url' => config('lexicon.api_url'),
            'client_code' => config('lexicon.client_code') ?: Arr::get($manifest, 'client'),
            'project_code' => config('lexicon.project_code') ?: Arr::get($manifest, 'project'),
            'secret' => config('lexicon.secret'),
            'environment' => config('lexicon.environment') ?: Arr::get($manifest, 'environment', 'local'),
            'languages' => Arr::get($manifest, 'languages', []),
            'areas' => Arr::get($manifest, 'areas', []),
            'extractors' => array_values((array) Arr::get($manifest, 'extractors', [])),
            'output' => array_merge(
                (array) config('lexicon.output', []),
                (array) Arr::get($manifest, 'output', []),
            ),
            'import' => array_merge(
                (array) config('lexicon.import', [
                    'base_path' => 'lang',
                    'auto_discover' => true,
                    'source_language' => 'en',
                    'formats' => ['php', 'json'],
                    'area_code_strategy' => 'relative_path',
                    'source_pattern' => '{base_path}/{locale}/{relative_path}',
                    'default_strategy' => 'create_only',
                    'exclude' => ['vendor', 'node_modules', '.git'],
                ]),
                (array) Arr::get($manifest, 'import', []),
            ),
        ];
    }
}
