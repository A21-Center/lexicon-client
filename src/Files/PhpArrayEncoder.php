<?php

namespace A21\LexiconClient\Files;

class PhpArrayEncoder
{
    /**
     * @param  array<string, mixed>  $content
     */
    public function encode(array $content): string
    {
        $exported = var_export($this->normalize($content), true);
        $exported = preg_replace('/^([ ]*)(.*)/m', '$1$1$2', $exported) ?? $exported;
        $exported = str_replace(['array (', ')'], ['[', ']'], $exported);
        $exported = preg_replace('/=>\s*\n\s*\[/', '=> [', $exported) ?? $exported;

        return "<?php\n\nreturn ".$exported.";\n";
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function normalize(array $content): array
    {
        $normalized = [];

        foreach ($content as $key => $value) {
            $normalized[(string) $key] = is_array($value)
                ? $this->normalize($value)
                : $value;
        }

        return $normalized;
    }
}
