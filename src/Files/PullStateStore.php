<?php

namespace A21\LexiconClient\Files;

use Illuminate\Support\Facades\File;

class PullStateStore
{
    public function __construct(
        private readonly string $path,
    ) {}

    public static function defaultPath(): self
    {
        return new self(base_path('.lexicon/pull-state.json'));
    }

    /**
     * @return array<string, string> map of relative output path => content hash
     */
    public function hashes(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);
        $hashes = is_array($decoded['hashes'] ?? null) ? $decoded['hashes'] : [];

        $normalized = [];
        foreach ($hashes as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $hashes
     */
    public function save(array $hashes): void
    {
        File::ensureDirectoryExists(dirname($this->path));
        File::put(
            $this->path,
            (string) json_encode(
                [
                    'version' => 1,
                    'updated_at' => gmdate('c'),
                    'hashes' => $hashes,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )."\n"
        );
    }

    public function path(): string
    {
        return $this->path;
    }
}
