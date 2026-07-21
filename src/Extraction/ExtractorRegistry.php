<?php

namespace A21\LexiconClient\Extraction;

class ExtractorRegistry
{
    /**
     * @var array<string, Extractor>
     */
    private array $extractors = [];

    public function register(Extractor $extractor): void
    {
        $this->extractors[$extractor->type()] = $extractor;
    }

    public function has(string $type): bool
    {
        return isset($this->extractors[$type]);
    }

    public function get(string $type): ?Extractor
    {
        return $this->extractors[$type] ?? null;
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->extractors);
    }
}
