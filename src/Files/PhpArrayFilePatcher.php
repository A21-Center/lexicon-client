<?php

namespace A21\LexiconClient\Files;

/**
 * Surgically insert missing translation leaves into an existing Laravel PHP array file
 * without regenerating the whole file (preserves comments, key order, formatting).
 */
class PhpArrayFilePatcher
{
    /**
     * @param  array<string, mixed>  $leaves  dotted path => scalar|array value
     */
    public function injectMissingLeaves(string $source, array $leaves): ?string
    {
        if ($leaves === []) {
            return $source;
        }

        uksort($leaves, static function (string $a, string $b): int {
            return substr_count($a, '.') <=> substr_count($b, '.');
        });

        foreach ($leaves as $path => $value) {
            $keys = explode('.', (string) $path);
            $patched = $this->injectOne($source, $keys, $value);

            if ($patched === null) {
                return null;
            }

            $source = $patched;
        }

        return $source;
    }

    /**
     * @param  list<string>  $keys
     */
    private function injectOne(string $source, array $keys, mixed $value): ?string
    {
        if ($keys === []) {
            return null;
        }

        $range = $this->findReturnArrayRange($source);
        if ($range === null) {
            return null;
        }

        [$start, $end] = $range;

        for ($i = 0; $i < count($keys) - 1; $i++) {
            $child = $this->findKeyArrayRange($source, $start, $end, $keys[$i]);
            if ($child === null) {
                // Parent missing: inject nested structure at this level for remaining keys.
                $nested = $this->nestValue(array_slice($keys, $i), $value);

                return $this->insertEntry($source, $start, $end, (string) array_key_first($nested), $nested[array_key_first($nested)]);
            }
            [$start, $end] = $child;
        }

        $leafKey = $keys[count($keys) - 1];

        if ($this->findKeyArrayRange($source, $start, $end, $leafKey) !== null
            || $this->keyExistsInArray($source, $start, $end, $leafKey)) {
            return $source;
        }

        return $this->insertEntry($source, $start, $end, $leafKey, $value);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function nestValue(array $keys, mixed $value): array
    {
        $nested = $value;
        for ($i = count($keys) - 1; $i >= 0; $i--) {
            $nested = [$keys[$i] => $nested];
        }

        return $nested;
    }

    /**
     * @return array{0: int, 1: int}|null  inclusive start/end indexes of `[` ... `]`
     */
    private function findReturnArrayRange(string $source): ?array
    {
        if (! preg_match('/\breturn\b/i', $source, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $pos = (int) $match[0][1] + strlen($match[0][0]);
        $open = $this->findNextSignificantChar($source, $pos, '[');
        if ($open === null) {
            return null;
        }

        $close = $this->findMatchingBracket($source, $open);
        if ($close === null) {
            return null;
        }

        return [$open, $close];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function findKeyArrayRange(string $source, int $arrayStart, int $arrayEnd, string $key): ?array
    {
        $valueStart = $this->findKeyValueStart($source, $arrayStart, $arrayEnd, $key);
        if ($valueStart === null) {
            return null;
        }

        $open = $this->findNextSignificantChar($source, $valueStart, '[');
        if ($open === null || $open >= $arrayEnd) {
            return null;
        }

        $close = $this->findMatchingBracket($source, $open);
        if ($close === null || $close > $arrayEnd) {
            return null;
        }

        return [$open, $close];
    }

    private function keyExistsInArray(string $source, int $arrayStart, int $arrayEnd, string $key): bool
    {
        return $this->findKeyValueStart($source, $arrayStart, $arrayEnd, $key) !== null;
    }

    private function findKeyValueStart(string $source, int $arrayStart, int $arrayEnd, string $key): ?int
    {
        $length = strlen($source);
        $depth = 0;
        $i = $arrayStart + 1;

        while ($i < $arrayEnd && $i < $length) {
            $char = $source[$i];

            // Keys are quoted strings — match them before skipString() would walk past.
            if ($depth === 0 && ($char === "'" || $char === '"') && $this->matchArrayKeyAt($source, $i, $key)) {
                $afterKey = $i + $this->quotedKeyLength($source, $i);
                $arrow = $this->findNextSignificantToken($source, $afterKey, '=>');
                if ($arrow === null || $arrow >= $arrayEnd) {
                    return null;
                }

                return $arrow + 2;
            }

            if ($char === "'" || $char === '"') {
                $i = $this->skipString($source, $i);
                continue;
            }

            if ($char === '/' && ($source[$i + 1] ?? '') === '/') {
                $nl = strpos($source, "\n", $i);
                $i = $nl === false ? $length : $nl + 1;
                continue;
            }

            if ($char === '/' && ($source[$i + 1] ?? '') === '*') {
                $end = strpos($source, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }

            if ($char === '[') {
                $depth++;
                $i++;
                continue;
            }

            if ($char === ']') {
                $depth--;
                $i++;
                continue;
            }

            $i++;
        }

        return null;
    }

    private function matchArrayKeyAt(string $source, int $pos, string $key): bool
    {
        $quote = $source[$pos] ?? '';
        if ($quote !== "'" && $quote !== '"') {
            return false;
        }

        $expected = $quote.$this->escapeForQuotes($key, $quote).$quote;

        return substr($source, $pos, strlen($expected)) === $expected;
    }

    private function quotedKeyLength(string $source, int $pos): int
    {
        $quote = $source[$pos];
        $i = $pos + 1;
        $length = strlen($source);

        while ($i < $length) {
            if ($source[$i] === '\\') {
                $i += 2;
                continue;
            }
            if ($source[$i] === $quote) {
                return ($i - $pos) + 1;
            }
            $i++;
        }

        return 1;
    }

    private function insertEntry(string $source, int $arrayStart, int $arrayEnd, string $key, mixed $value): ?string
    {
        $indent = $this->detectEntryIndent($source, $arrayStart, $arrayEnd);
        [$insertAt, $singleLine] = $this->resolveInsertPosition($source, $arrayStart, $arrayEnd);

        $before = substr($source, 0, $insertAt);
        $after = substr($source, $insertAt);
        $before = $this->ensureTrailingCommaBeforeInsert($before, $arrayStart);

        if ($singleLine) {
            return $before.' '.$this->formatEntryInline($key, $value).$after;
        }

        return $before.$this->formatEntry($key, $value, $indent).$after;
    }

    /**
     * @return array{0: int, 1: bool} insert offset + whether the array is single-line
     */
    private function resolveInsertPosition(string $source, int $arrayStart, int $arrayEnd): array
    {
        $lineStart = $arrayEnd;
        while ($lineStart > 0 && $source[$lineStart - 1] !== "\n") {
            $lineStart--;
        }

        $beforeBracketOnLine = trim(substr($source, $lineStart, $arrayEnd - $lineStart));
        $singleLine = $beforeBracketOnLine !== '';

        // Single-line: insert immediately before `]`.
        // Multi-line (including `]` alone on its line): insert at start of that line.
        return [$singleLine ? $arrayEnd : $lineStart, $singleLine];
    }

    private function formatEntryInline(string $key, mixed $value): string
    {
        return "'".$this->escapeForQuotes($key, "'")."' => ".$this->exportValue($value, '').',';
    }

    private function ensureTrailingCommaBeforeInsert(string $before, int $arrayStart): string
    {
        $trimEnd = rtrim($before);
        if ($trimEnd === '' || strlen($trimEnd) <= $arrayStart) {
            return $before;
        }

        $last = $trimEnd[strlen($trimEnd) - 1];
        if ($last === '[' || $last === ',') {
            return $before;
        }

        // Insert comma after last meaningful token.
        return $trimEnd.",\n".substr($before, strlen($trimEnd));
    }

    private function detectEntryIndent(string $source, int $arrayStart, int $arrayEnd): string
    {
        if (preg_match('/\n([ \t]+)[\'"]/', substr($source, $arrayStart, max(0, $arrayEnd - $arrayStart)), $match) === 1) {
            return $match[1];
        }

        // Fallback: parent indent + 4 spaces.
        $lineStart = $arrayStart;
        while ($lineStart > 0 && $source[$lineStart - 1] !== "\n") {
            $lineStart--;
        }
        $parentIndent = '';
        while (($source[$lineStart] ?? '') === ' ' || ($source[$lineStart] ?? '') === "\t") {
            $parentIndent .= $source[$lineStart];
            $lineStart++;
        }

        return $parentIndent.'    ';
    }

    private function formatEntry(string $key, mixed $value, string $indent): string
    {
        $exported = $this->exportValue($value, $indent);

        return $indent."'".$this->escapeForQuotes($key, "'")."' => ".$exported.",\n";
    }

    private function exportValue(mixed $value, string $indent): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $childIndent = $indent.'    ';
            $lines = ["["];
            foreach ($value as $childKey => $childValue) {
                $lines[] = $childIndent."'".$this->escapeForQuotes((string) $childKey, "'")."' => ".$this->exportValue($childValue, $childIndent).",";
            }
            $lines[] = $indent.']';

            return implode("\n", $lines);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'".$this->escapeForQuotes((string) $value, "'")."'";
    }

    private function escapeForQuotes(string $value, string $quote): string
    {
        return str_replace(
            ['\\', $quote, "\n", "\r", "\t"],
            ['\\\\', '\\'.$quote, '\\n', '\\r', '\\t'],
            $value
        );
    }

    private function findNextSignificantChar(string $source, int $from, string $needle): ?int
    {
        $length = strlen($source);
        $i = $from;

        while ($i < $length) {
            $char = $source[$i];

            if ($char === "'" || $char === '"') {
                $i = $this->skipString($source, $i);
                continue;
            }

            if ($char === '/' && ($source[$i + 1] ?? '') === '/') {
                $nl = strpos($source, "\n", $i);
                $i = $nl === false ? $length : $nl + 1;
                continue;
            }

            if ($char === '/' && ($source[$i + 1] ?? '') === '*') {
                $end = strpos($source, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            return $char === $needle ? $i : null;
        }

        return null;
    }

    private function findNextSignificantToken(string $source, int $from, string $token): ?int
    {
        $length = strlen($source);
        $i = $from;
        $tokenLength = strlen($token);

        while ($i < $length) {
            $char = $source[$i];

            if ($char === "'" || $char === '"') {
                $i = $this->skipString($source, $i);
                continue;
            }

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            if (substr($source, $i, $tokenLength) === $token) {
                return $i;
            }

            return null;
        }

        return null;
    }

    private function findMatchingBracket(string $source, int $openPos): ?int
    {
        $length = strlen($source);
        $depth = 0;

        for ($i = $openPos; $i < $length; $i++) {
            $char = $source[$i];

            if ($char === "'" || $char === '"') {
                $i = $this->skipString($source, $i) - 1;
                continue;
            }

            if ($char === '/' && ($source[$i + 1] ?? '') === '/') {
                $nl = strpos($source, "\n", $i);
                if ($nl === false) {
                    return null;
                }
                $i = $nl;
                continue;
            }

            if ($char === '/' && ($source[$i + 1] ?? '') === '*') {
                $end = strpos($source, '*/', $i + 2);
                if ($end === false) {
                    return null;
                }
                $i = $end + 1;
                continue;
            }

            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function skipString(string $source, int $pos): int
    {
        $quote = $source[$pos];
        $length = strlen($source);
        $i = $pos + 1;

        while ($i < $length) {
            if ($source[$i] === '\\') {
                $i += 2;
                continue;
            }
            if ($source[$i] === $quote) {
                return $i + 1;
            }
            $i++;
        }

        return $length;
    }
}
