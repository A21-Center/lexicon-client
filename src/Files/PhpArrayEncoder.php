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
        // Convert array() to [] without touching parentheses inside string literals.
        $exported = $this->convertArraySyntax($exported);
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

    /**
     * Replace `array (` ... `)` with `[` ... `]` while leaving `)` inside quotes intact.
     */
    private function convertArraySyntax(string $exported): string
    {
        $exported = preg_replace('/\barray\s*\(/', '[', $exported) ?? $exported;

        $out = '';
        $len = strlen($exported);
        $quote = null;
        $escape = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $exported[$i];

            if ($quote !== null) {
                $out .= $ch;
                if ($escape) {
                    $escape = false;
                    continue;
                }
                // Single backslash — must be PHP '\\' (one char), not '\\\\' (two).
                if ($ch === "\\") {
                    $escape = true;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $out .= $ch;
                continue;
            }

            if ($ch === ')') {
                $out .= ']';
                continue;
            }

            $out .= $ch;
        }

        return $out;
    }
}
