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
            'output' => array_merge(
                (array) config('lexicon.output', []),
                (array) Arr::get($manifest, 'output', []),
            ),
        ];
    }
}
