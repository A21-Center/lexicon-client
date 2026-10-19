<?php

namespace A21\LexiconClient\Files;

class RelativePathResolver
{
    public function fromArea(string $areaCode, ?string $storedPath = null, string $extension = 'php'): string
    {
        if (is_string($storedPath) && trim($storedPath) !== '') {
            return ltrim(str_replace('\\', '/', $storedPath), '/');
        }

        $path = str_replace('.', '/', trim($areaCode, '.'));

        if ($path === '') {
            return 'translations.'.$extension;
        }

        if (! str_ends_with(strtolower($path), '.'.$extension)) {
            $path .= '.'.$extension;
        }

        return $path;
    }
}
