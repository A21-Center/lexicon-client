<?php

namespace A21\LexiconClient\Files;

class RelativePathResolver
{
    public function fromArea(string $areaCode, ?string $storedPath = null, string $extension = 'php'): string
    {
        if (is_string($storedPath) && trim($storedPath) !== '') {
            $normalized = str_replace('\\', '/', trim($storedPath));
            $normalized = ltrim($normalized, '/');

            if ($normalized === ''
                || str_contains($normalized, '..')
                || str_starts_with($normalized, '/')
                || preg_match('#^[A-Za-z]:/#', $normalized) === 1
            ) {
                throw new \InvalidArgumentException('Invalid relative_path: path traversal is not allowed.');
            }

            return $normalized;
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
