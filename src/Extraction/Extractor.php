<?php

namespace A21\LexiconClient\Extraction;

interface Extractor
{
    /**
     * The manifest `type` this extractor handles (files|database|blade|content|...).
     */
    public function type(): string;

    /**
     * Produce translatable entries from a single extractor definition.
     *
     * @param  array<string, mixed>  $definition
     * @return list<ExtractedEntry>
     */
    public function extract(array $definition): array;
}
