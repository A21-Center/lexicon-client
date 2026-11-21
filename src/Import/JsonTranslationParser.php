<?php

namespace A21\LexiconClient\Import;

use RuntimeException;

class JsonTranslationParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $absolutePath): array
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException("Translation file not found: {$absolutePath}");
        }

        $decoded = json_decode((string) file_get_contents($absolutePath), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("JSON translation file must contain an object/array: {$absolutePath}");
        }

        return $decoded;
    }
}
