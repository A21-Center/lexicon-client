<?php

namespace A21\LexiconClient\Import;

use RuntimeException;

class PhpTranslationParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $absolutePath): array
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException("Translation file not found: {$absolutePath}");
        }

        /** @var mixed $data */
        $data = include $absolutePath;

        if (! is_array($data)) {
            throw new RuntimeException("PHP translation file must return an array: {$absolutePath}");
        }

        return $data;
    }
}
