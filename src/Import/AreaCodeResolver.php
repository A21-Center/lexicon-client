<?php

namespace A21\LexiconClient\Import;

class AreaCodeResolver
{
    public function fromRelativePath(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', trim($relativePath));
        $withoutExtension = preg_replace('/\.(php|json|ya?ml|csv|po)$/i', '', $normalized) ?? $normalized;
        $withoutExtension = trim($withoutExtension, '/');
        $withUnderscores = preg_replace('/\s+/', '_', $withoutExtension) ?? $withoutExtension;
        $code = strtolower(str_replace('/', '.', $withUnderscores));
        $code = preg_replace('/[^a-z0-9._-]/', '', $code) ?? $code;
        $code = preg_replace('/\.+/', '.', $code) ?? $code;

        return trim($code, '.');
    }

    public function sourcePathPattern(string $basePath, string $relativePath): string
    {
        $base = trim(str_replace('\\', '/', $basePath), '/');
        $relative = ltrim(str_replace('\\', '/', $relativePath), '/');

        return ($base !== '' ? $base.'/' : '').'{locale}/'.$relative;
    }
}
