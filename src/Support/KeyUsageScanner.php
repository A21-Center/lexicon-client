<?php

namespace A21\LexiconClient\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use SplFileInfo;

/**
 * Key usage scanner for Blade/PHP/JS/TS/Vue files.
 * Matches exact literals, prefix concatenations, and full keys passed
 * through a variable when that variable is built from literals or a prefix.
 *
 * Limitation (package users): a key that exists only at runtime cannot be resolved, e.g.
 *   $key = $request->input('field'); __($key);
 * Those call sites are invisible to static scan/Move rewrite. Prefer string literals
 * or reconstructible prefix+leaf helpers. See README “Key usage scan & Move rewrite”.
 */
class KeyUsageScanner
{
    /** @var list<string> */
    private const EXTENSIONS = ['php', 'blade.php', 'js', 'jsx', 'ts', 'tsx', 'vue'];

    /**
     * Prefixes whose suffix is fully dynamic (JS template, unbound PHP variable).
     * Any catalog key under the prefix is treated as used.
     *
     * @var list<array{prefix: string, evidence: list<array{path: string, line: int}>}>
     */
    private array $openPrefixes = [];

    /**
     * @param  list<string>  $roots
     * @return array<string, list<array{path: string, line: int}>>
     */
    public function scan(array $roots): array
    {
        $found = [];

        foreach ($roots as $root) {
            $root = rtrim($root, DIRECTORY_SEPARATOR);
            if ($root === '' || ! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();
                if (! $this->isScannable($path) || $this->shouldSkip($path)) {
                    continue;
                }

                $relative = $this->relativePath($path);
                $contents = @file_get_contents($path);
                if (! is_string($contents) || $contents === '') {
                    continue;
                }

                foreach ($this->extractLiterals($contents) as $match) {
                    $key = $match['key'];
                    $found[$key] ??= [];
                    $found[$key][] = [
                        'path' => $relative,
                        'line' => $match['line'],
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $catalogKeys
     * @param  array<string, list<array{path: string, line: int}>>  $foundInCode
     * @return list<array{full_key: string, status: string, evidence: list<array{path: string, line: int}>}>
     */
    public function buildResults(array $catalogKeys, array $foundInCode): array
    {
        $index = $this->buildNormalizedEvidenceIndex($foundInCode);

        $results = [];
        foreach ($catalogKeys as $fullKey) {
            $key = trim((string) $fullKey);
            if ($key === '') {
                continue;
            }

            $evidence = $index[$key]
                ?? $index[strtolower($key)]
                ?? $foundInCode[$key]
                ?? $this->evidenceForOpenPrefix($key);
            $results[] = [
                'full_key' => $key,
                'status' => $evidence !== [] ? 'used' : 'unused',
                'evidence' => array_values($evidence),
            ];
        }

        return $results;
    }

    /**
     * Normalize client literals toward Lexicon full_key form.
     * Examples:
     * - api/studio/artwork.x → studio.artwork.x
     * - emails/gallery/account/new-billing-address.intro → gallery.account.new_billing_address.intro
     */
    public function normalizeKeyForLexicon(string $key): string
    {
        $normalized = preg_replace('/\s+/', '_', trim($key)) ?? trim($key);
        $normalized = str_replace(['/', '-'], ['.', '_'], $normalized);
        foreach (['emails.', 'api.'] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                $normalized = substr($normalized, strlen($prefix));
            }
        }

        return $normalized;
    }

    /**
     * Expand scan hits with Message::get('leaf') resolved against __('prefix.'.$key) helpers.
     *
     * @param  list<string>  $roots
     * @param  array<string, list<array{path: string, line: int}>>  $foundInCode
     * @return array<string, list<array{path: string, line: int}>>
     */
    public function expandMessageHelperUsage(array $roots, array $foundInCode): array
    {
        $files = [];
        foreach ($roots as $root) {
            $root = rtrim($root, DIRECTORY_SEPARATOR);
            if ($root === '' || ! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! str_ends_with(strtolower($file->getFilename()), '.php')) {
                    continue;
                }
                $path = $file->getPathname();
                if ($this->shouldSkip($path)) {
                    continue;
                }
                $files[] = $path;
            }
        }

        $classToPrefix = [];
        foreach ($files as $path) {
            $contents = @file_get_contents($path);
            if (! is_string($contents) || $contents === '') {
                continue;
            }
            if (! preg_match('/class\\s+(\\w+Message)\\b/', $contents, $classMatch)) {
                continue;
            }
            if (! preg_match("/__\\(\\s*['\"]([^'\"]+)['\"]\\s*\\.\\s*\\\$key/", $contents, $prefixMatch)) {
                continue;
            }
            $classToPrefix[$classMatch[1]] = $this->normalizeKeyForLexicon(rtrim($prefixMatch[1], '.'));
        }

        if ($classToPrefix === []) {
            return $foundInCode;
        }

        $expanded = $foundInCode;
        foreach ($files as $path) {
            $contents = @file_get_contents($path);
            if (! is_string($contents) || $contents === '') {
                continue;
            }
            $relative = $this->relativePath($path);
            if (! preg_match_all(
                "/(\\w+Message)::get\\(\\s*['\"]([^'\"]+)['\"]/u",
                $contents,
                $calls,
                PREG_OFFSET_CAPTURE,
            )) {
                continue;
            }

            foreach ($calls[1] as $idx => $classHit) {
                $class = (string) $classHit[0];
                $leaf = trim((string) $calls[2][$idx][0]);
                $prefix = $classToPrefix[$class] ?? null;
                if ($prefix === null || $leaf === '') {
                    continue;
                }
                if (str_contains($leaf, '$') || str_contains($leaf, '{')) {
                    $this->rememberOpenPrefix($prefix.'.', $relative, $contents, (int) $calls[2][$idx][1]);
                    continue;
                }
                $full = $prefix.'.'.$this->normalizeKeyForLexicon($leaf);
                $offset = (int) $calls[2][$idx][1];
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $expanded[$full] ??= [];
                $expanded[$full][] = ['path' => $relative, 'line' => $line];
            }
        }

        return $expanded;
    }

    /**
     * Resolve same-file helpers that prepend a translation prefix.
     *
     * filled_trans('static pages/home.'.$key) inside $homeText(),
     * then $homeText('1_Slide.title') or $homeText('hashtags.items.'.$item).
     *
     * @param  list<string>  $roots
     * @param  array<string, list<array{path: string, line: int}>>  $foundInCode
     * @return array<string, list<array{path: string, line: int}>>
     */
    public function expandConcatenatedPrefixUsage(array $roots, array $foundInCode): array
    {
        $expanded = $foundInCode;

        foreach ($this->iterSourceFiles($roots, ['php']) as $path) {
            $contents = @file_get_contents($path);
            if (! is_string($contents) || $contents === '') {
                continue;
            }

            $wrappers = $this->translationPrefixWrappers($contents);
            $relative = $this->relativePath($path);

            if ($wrappers !== [] && preg_match_all('/\$(\w+)\(\s*[\'"]([^\'"]+)[\'"]/u', $contents, $calls, PREG_OFFSET_CAPTURE)) {
                foreach ($calls[1] as $index => $nameHit) {
                    $prefix = $wrappers[(string) $nameHit[0]] ?? null;
                    $leaf = trim((string) $calls[2][$index][0]);
                    if ($prefix === null || $leaf === '' || str_contains($leaf, '$') || str_contains($leaf, ' ')) {
                        continue;
                    }
                    $this->rememberFoundKey($expanded, $prefix.$leaf, $relative, $contents, (int) $calls[2][$index][1]);
                }
            }

            if ($wrappers !== [] && preg_match_all('/\$(\w+)\(\s*[\'"]([^\'"]+)[\'"]\s*\.\s*\$(\w+)/u', $contents, $nested, PREG_OFFSET_CAPTURE)) {
                foreach ($nested[1] as $index => $nameHit) {
                    $base = $wrappers[(string) $nameHit[0]] ?? null;
                    $partial = trim((string) $nested[2][$index][0]);
                    $variable = (string) $nested[3][$index][0];
                    if ($base === null || $partial === '') {
                        continue;
                    }
                    $offset = (int) $nested[2][$index][1];
                    $items = $this->arrayStringLiterals($contents, $variable);
                    if ($items === []) {
                        $this->rememberOpenPrefix($base.$partial, $relative, $contents, $offset);
                        continue;
                    }
                    foreach ($items as $item) {
                        $this->rememberFoundKey($expanded, $base.$partial.$item, $relative, $contents, $offset);
                    }
                }
            }

            $this->collectDirectDynamicPrefixes($contents, $relative, $wrappers, $expanded);
        }

        foreach ($this->iterSourceFiles($roots, ['js', 'jsx', 'ts', 'tsx', 'vue']) as $path) {
            $contents = @file_get_contents($path);
            if (! is_string($contents) || $contents === '') {
                continue;
            }
            $this->collectScriptDynamicPrefixes($contents, $this->relativePath($path));
        }

        return $this->expandComputedKeyUsage($roots, $expanded);
    }

    /**
     * Resolve __($key) / t(key) when the whole key is a variable.
     *
     * Follows assignments, array values, function arguments and return prefixes.
     * A bare translator wrapper (function t(key)) does not mark the catalog.
     *
     * @param  list<string>  $roots
     * @param  array<string, list<array{path: string, line: int}>>  $foundInCode
     * @return array<string, list<array{path: string, line: int}>>
     */
    public function expandComputedKeyUsage(array $roots, array $foundInCode): array
    {
        $expanded = $foundInCode;
        $files = [];
        foreach ($this->iterSourceFiles($roots, ['php', 'js', 'jsx', 'ts', 'tsx', 'vue']) as $path) {
            if (str_ends_with(strtolower($path), '.min.js')) {
                continue;
            }
            $contents = @file_get_contents($path);
            if (! is_string($contents) || $contents === '' || strlen($contents) > 1500000) {
                continue;
            }
            $files[] = [
                'relative' => $this->relativePath($path),
                'contents' => $contents,
            ];
        }

        $registry = [];
        $calls = [];
        foreach ($files as $file) {
            foreach ($this->extractNamedFunctions($file['contents']) as $function) {
                $function['produced'] = $this->producedFrom($function['body']);
                $registry[$function['name']][] = $function;
                foreach ($this->collectCalls($function['body']) as $call) {
                    $call['callerBody'] = $function['body'];
                    $calls[$call['name']][] = $call;
                }
            }
            foreach ($this->collectCalls($file['contents']) as $call) {
                $call['callerBody'] = $file['contents'];
                $calls[$call['name']][] = $call;
            }
        }

        foreach ($files as $file) {
            $functions = $this->extractNamedFunctions($file['contents']);
            foreach ($this->dynamicTranslationCalls($file['contents']) as $call) {
                $owner = $this->innermostFunction($functions, $call['offset']);
                $functionName = $owner['name'] ?? '';
                $params = $owner['params'] ?? [];
                $scope = $owner['body'] ?? $file['contents'];
                $variable = $this->dynamicVariableName($call['expr']);
                if (
                    in_array($functionName, ['t', 'trans', 'filled_trans'], true)
                    && $variable !== ''
                    && (($params[0] ?? '') === $variable)
                ) {
                    continue;
                }

                $seen = [];
                $resolved = $this->resolveDynamicExpression(
                    $call['expr'],
                    $scope,
                    $file['contents'],
                    $params,
                    $functionName,
                    $registry,
                    $calls,
                    $seen,
                );
                foreach ($resolved['keys'] as $key) {
                    $this->rememberFoundKey($expanded, $key, $file['relative'], $file['contents'], $call['offset']);
                }
                foreach ($resolved['prefixes'] as $prefix) {
                    if (! str_ends_with($prefix, '.')) {
                        $prefix .= '.';
                    }
                    $this->rememberOpenPrefix($prefix, $file['relative'], $file['contents'], $call['offset']);
                }
            }
        }

        return $expanded;
    }

    /**
     * @return list<array{expr: string, offset: int}>
     */
    private function dynamicTranslationCalls(string $contents): array
    {
        $calls = [];
        $patterns = [
            '/(?:__|trans(?:_choice)?|filled_trans|@lang|Lang::(?:get|has))\(\s*(\$[A-Za-z_][A-Za-z0-9_]*(?:\s*\[[^\]]+\])?(?:\s*\?\?\s*(?:\'[^\']*\'|"[^"]*"))?)/u',
            '/(?<!function\s)(?<!\.)(?:\$t|\bi18n\.t|\bt)\(\s*([A-Za-z_$][\w.]*)\s*[,)]/u',
        ];
        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[1] as $hit) {
                $calls[] = ['expr' => (string) $hit[0], 'offset' => (int) $hit[1]];
            }
        }

        return $calls;
    }

    /**
     * @param  list<array{name: string, params: list<string>, body: string, start: int, end: int}>  $functions
     * @return array{name: string, params: list<string>, body: string, start: int, end: int}|null
     */
    private function innermostFunction(array $functions, int $offset): ?array
    {
        $best = null;
        $bestSpan = PHP_INT_MAX;
        foreach ($functions as $function) {
            if ($offset < $function['start'] || $offset > $function['end']) {
                continue;
            }
            $span = $function['end'] - $function['start'];
            if ($span < $bestSpan) {
                $best = $function;
                $bestSpan = $span;
            }
        }

        return $best;
    }

    private function dynamicVariableName(string $expr): string
    {
        $base = preg_replace('/\s*\?\?.*$/', '', $expr) ?? $expr;
        if (preg_match('/^\$?([A-Za-z_][A-Za-z0-9_]*)/', $base, $match)) {
            return (string) $match[1];
        }

        return '';
    }

    /**
     * @param  list<string>  $params
     * @param  array<string, list<array{name: string, params: list<string>, body: string, produced: array{keys: list<string>, prefixes: list<string>}}>>  $registry
     * @param  array<string, list<array{name: string, args: list<string>, callerBody: string}>>  $calls
     * @param  array<string, bool>  $seen
     * @return array{keys: list<string>, prefixes: list<string>}
     */
    private function resolveDynamicExpression(
        string $expr,
        string $scope,
        string $fileContents,
        array $params,
        string $functionName,
        array $registry,
        array $calls,
        array &$seen,
    ): array {
        $keys = [];
        $prefixes = [];
        if (preg_match('/\?\?\s*[\'"]([^\'"]+)[\'"]/u', $expr, $fallback) && $this->looksLikeTranslationKey($fallback[1])) {
            $keys[] = (string) $fallback[1];
        }

        $base = trim((string) (preg_replace('/\s*\?\?.*$/', '', $expr) ?? $expr));
        if (preg_match('/\.([A-Za-z_][A-Za-z0-9_]*)$/', $base, $property)) {
            $this->absorbProperty((string) $property[1], $fileContents, $keys, $prefixes);

            return ['keys' => array_values(array_unique($keys)), 'prefixes' => array_values(array_unique($prefixes))];
        }

        $name = $this->dynamicVariableName($base);
        if ($name === '') {
            return ['keys' => array_values(array_unique($keys)), 'prefixes' => array_values(array_unique($prefixes))];
        }

        $this->absorbVariable($name, $scope, $params, $functionName, $registry, $calls, $keys, $prefixes, $seen);
        if ($keys === [] && $prefixes === [] && $scope !== $fileContents) {
            $this->absorbVariable($name, $fileContents, [], '', $registry, $calls, $keys, $prefixes, $seen);
        }

        return ['keys' => array_values(array_unique($keys)), 'prefixes' => array_values(array_unique($prefixes))];
    }

    /**
     * @param  list<string>  $params
     * @param  array<string, list<array{name: string, params: list<string>, body: string, produced: array{keys: list<string>, prefixes: list<string>}}>>  $registry
     * @param  array<string, list<array{name: string, args: list<string>, callerBody: string}>>  $calls
     * @param  list<string>  $keys
     * @param  list<string>  $prefixes
     * @param  array<string, bool>  $seen
     */
    private function absorbVariable(
        string $name,
        string $scope,
        array $params,
        string $functionName,
        array $registry,
        array $calls,
        array &$keys,
        array &$prefixes,
        array &$seen,
    ): void {
        $token = $functionName.'#'.$name.'#'.strlen($scope);
        if (isset($seen[$token]) || $name === '') {
            return;
        }
        $seen[$token] = true;
        $quoted = preg_quote($name, '/');

        if (preg_match_all('/(?<![\w$])\$?'.$quoted.'\s*=\s*\'([^\']+)\'\s*\./u', $scope, $assigned)) {
            foreach ($assigned[1] as $prefix) {
                $prefixes[] = (string) $prefix;
            }
        }
        if (preg_match_all('/(?<![\w$])\$?'.$quoted.'\s*=\s*"([^"$]+)"\s*\./u', $scope, $assigned)) {
            foreach ($assigned[1] as $prefix) {
                $prefixes[] = (string) $prefix;
            }
        }
        if (preg_match_all('/(?<![\w$])'.$quoted.'\s*=\s*`([^`$]*)\$\{/u', $scope, $assigned)) {
            foreach ($assigned[1] as $prefix) {
                $prefixes[] = (string) $prefix;
            }
        }
        if (preg_match_all('/(?<![\w$])\$?'.$quoted.'\s*=\s*([\'"])([^\'"]+)\1(?!\s*\.)/u', $scope, $assigned)) {
            foreach ($assigned[2] as $key) {
                if ($this->looksLikeTranslationKey((string) $key)) {
                    $keys[] = (string) $key;
                }
            }
        }
        if (preg_match_all('/(?<![\w$])\$?'.$quoted.'\s*=\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/u', $scope, $assigned)) {
            foreach ($assigned[1] as $callee) {
                if (! in_array((string) $callee, ['function', 'array', 'list'], true)) {
                    $this->absorbProduced((string) $callee, $registry, $calls, $keys, $prefixes, $seen);
                }
            }
        }
        if (preg_match('/(?<![\w$])\$?'.$quoted.'\s*=\s*\[([\s\S]*?)\]/u', $scope, $array)) {
            if (preg_match_all('/=>\s*[\'"]([^\'"]+)[\'"]/u', $array[1], $values)) {
                foreach ($values[1] as $key) {
                    if ($this->looksLikeTranslationKey((string) $key)) {
                        $keys[] = (string) $key;
                    }
                }
            }
        }
        if (preg_match_all('/(?<![\w$])\$?'.$quoted.'\.push\(\s*\'([^\']+)\'/u', $scope, $pushed)) {
            foreach ($pushed[1] as $key) {
                if ($this->looksLikeTranslationKey((string) $key)) {
                    $keys[] = (string) $key;
                }
            }
        }
        if (preg_match_all('/(?<![\w$])\$?'.$quoted.'\.push\(\s*"([^"]+)"/u', $scope, $pushed)) {
            foreach ($pushed[1] as $key) {
                if ($this->looksLikeTranslationKey((string) $key)) {
                    $keys[] = (string) $key;
                }
            }
        }
        if (preg_match_all('/(?<![\w$])\$?'.$quoted.'\.push\(\s*`([^`$]*)\$\{/u', $scope, $pushed)) {
            foreach ($pushed[1] as $prefix) {
                $prefixes[] = (string) $prefix;
            }
        }
        if (preg_match('/foreach\s*\(\s*\$(\w+)\s+as\s+\$'.$quoted.'\b/u', $scope, $loop)) {
            $this->absorbVariable((string) $loop[1], $scope, $params, $functionName, $registry, $calls, $keys, $prefixes, $seen);
        }
        if (preg_match('/for\s*\(\s*(?:const|let|var)\s+'.$quoted.'\s+of\s+(\w+)/u', $scope, $loop)) {
            $this->absorbVariable((string) $loop[1], $scope, $params, $functionName, $registry, $calls, $keys, $prefixes, $seen);
        }

        $index = array_search($name, $params, true);
        if ($index !== false && $functionName !== '' && $functionName !== 't') {
            foreach ($calls[$functionName] ?? [] as $call) {
                $argument = $call['args'][$index] ?? null;
                if (! is_string($argument)) {
                    continue;
                }
                $this->absorbArgument($argument, (string) $call['callerBody'], $registry, $calls, $keys, $prefixes, $seen);
            }
        }
    }

    /**
     * @param  array<string, list<array{name: string, params: list<string>, body: string, produced: array{keys: list<string>, prefixes: list<string>}}>>  $registry
     * @param  array<string, list<array{name: string, args: list<string>, callerBody: string}>>  $calls
     * @param  list<string>  $keys
     * @param  list<string>  $prefixes
     * @param  array<string, bool>  $seen
     */
    private function absorbArgument(
        string $argument,
        string $callerBody,
        array $registry,
        array $calls,
        array &$keys,
        array &$prefixes,
        array &$seen,
    ): void {
        $argument = trim($argument);
        if (preg_match('/^[\'"]([^\'"]+)[\'"]$/u', $argument, $literal)) {
            if ($this->looksLikeTranslationKey($literal[1])) {
                $keys[] = (string) $literal[1];
            }

            return;
        }
        if (preg_match('/^`([^`$]*)\$\{/u', $argument, $template)) {
            $prefixes[] = (string) $template[1];

            return;
        }
        if (preg_match('/^[\'"]([^\'"]+)[\'"]\s*\./u', $argument, $prefix)) {
            $prefixes[] = (string) $prefix[1];

            return;
        }
        if (preg_match('/^(\w+)\s*\(/u', $argument, $callee)) {
            $this->absorbProduced((string) $callee[1], $registry, $calls, $keys, $prefixes, $seen);

            return;
        }
        if (preg_match('/^\$?([A-Za-z_][A-Za-z0-9_]*)$/u', $argument, $variable)) {
            $this->absorbVariable((string) $variable[1], $callerBody, [], '', $registry, $calls, $keys, $prefixes, $seen);
        }
    }

    /**
     * @param  array<string, list<array{name: string, params: list<string>, body: string, produced: array{keys: list<string>, prefixes: list<string>}}>>  $registry
     * @param  array<string, list<array{name: string, args: list<string>, callerBody: string}>>  $calls
     * @param  list<string>  $keys
     * @param  list<string>  $prefixes
     * @param  array<string, bool>  $seen
     */
    private function absorbProduced(
        string $functionName,
        array $registry,
        array $calls,
        array &$keys,
        array &$prefixes,
        array &$seen,
    ): void {
        if ($functionName === '' || isset($seen['produced:'.$functionName])) {
            return;
        }
        $seen['produced:'.$functionName] = true;
        foreach ($registry[$functionName] ?? [] as $function) {
            foreach ($function['produced']['keys'] as $key) {
                $keys[] = $key;
            }
            foreach ($function['produced']['prefixes'] as $prefix) {
                $prefixes[] = $prefix;
            }
            if (preg_match_all('/return\s+\$?([A-Za-z_][A-Za-z0-9_]*)\s*;/u', $function['body'], $returned)) {
                foreach ($returned[1] as $variable) {
                    if (in_array((string) $variable, ['null', 'true', 'false'], true)) {
                        continue;
                    }
                    $this->absorbVariable(
                        (string) $variable,
                        $function['body'],
                        $function['params'],
                        $function['name'],
                        $registry,
                        $calls,
                        $keys,
                        $prefixes,
                        $seen,
                    );
                }
            }
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $prefixes
     */
    private function absorbProperty(string $property, string $contents, array &$keys, array &$prefixes): void
    {
        $quoted = preg_quote($property, '/');
        if (preg_match_all('/'.$quoted.'\s*:\s*[\'"]([^\'"]+)[\'"]/u', $contents, $literals)) {
            foreach ($literals[1] as $key) {
                if ($this->looksLikeTranslationKey((string) $key)) {
                    $keys[] = (string) $key;
                }
            }
        }
        if (preg_match_all('/'.$quoted.'\s*:\s*`([^`$]*)\$\{/u', $contents, $templates)) {
            foreach ($templates[1] as $prefix) {
                $prefixes[] = (string) $prefix;
            }
        }
    }

    /**
     * @return array{keys: list<string>, prefixes: list<string>}
     */
    private function producedFrom(string $body): array
    {
        $keys = [];
        $prefixes = [];
        if (preg_match_all('/return\s+\'([^\']*)\'\s*\./u', $body, $matches)) {
            foreach ($matches[1] as $prefix) {
                $prefixes[] = (string) $prefix;
            }
        }
        if (preg_match_all('/return\s+"([^"$]*)"\s*\./u', $body, $matches)) {
            foreach ($matches[1] as $prefix) {
                $prefixes[] = (string) $prefix;
            }
        }
        if (preg_match_all('/return\s+`([^`$]*)\$\{/u', $body, $matches)) {
            foreach ($matches[1] as $prefix) {
                $prefixes[] = (string) $prefix;
            }
        }
        if (preg_match_all('/return\s+\'([^\']+)\'(?!\s*\.)/u', $body, $matches)) {
            foreach ($matches[1] as $key) {
                if ($this->looksLikeTranslationKey((string) $key)) {
                    $keys[] = (string) $key;
                }
            }
        }
        if (preg_match_all('/\.push\(\s*\'([^\']+)\'/u', $body, $matches)) {
            foreach ($matches[1] as $key) {
                if ($this->looksLikeTranslationKey((string) $key)) {
                    $keys[] = (string) $key;
                }
            }
        }
        if (preg_match_all('/\.push\(\s*`([^`$]*)\$\{/u', $body, $matches)) {
            foreach ($matches[1] as $prefix) {
                $prefixes[] = (string) $prefix;
            }
        }

        return [
            'keys' => array_values(array_unique($keys)),
            'prefixes' => array_values(array_unique($prefixes)),
        ];
    }

    /**
     * @return list<array{name: string, params: list<string>, body: string, start: int, end: int}>
     */
    private function extractNamedFunctions(string $contents): array
    {
        $functions = [];
        $patterns = [
            '/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)(?:\s*:\s*[^\{]+)?\s*\{/u',
            '/(?:const|let|var)\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:async\s*)?\(([^)]*)\)\s*=>\s*\{/u',
            '/(?:const|let|var)\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*function\s*\(([^)]*)\)\s*\{/u',
        ];
        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as $index => $full) {
                $open = (int) $full[1] + strlen((string) $full[0]) - 1;
                $close = $this->matchingDelimiter($contents, $open, '{', '}');
                if ($close === null) {
                    continue;
                }
                $functions[] = [
                    'name' => (string) $matches[1][$index][0],
                    'params' => $this->parameterNames((string) $matches[2][$index][0]),
                    'body' => substr($contents, $open + 1, $close - $open - 1),
                    'start' => (int) $full[1],
                    'end' => $close,
                ];
            }
        }

        return $functions;
    }

    /**
     * @return list<string>
     */
    private function parameterNames(string $raw): array
    {
        $names = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/\$([A-Za-z_][A-Za-z0-9_]*)/u', $part, $match)) {
                $names[] = (string) $match[1];

                continue;
            }
            if (preg_match('/([A-Za-z_][A-Za-z0-9_]*)/u', $part, $match)) {
                $names[] = (string) $match[1];
            }
        }

        return $names;
    }

    /**
     * @return list<array{name: string, args: list<string>}>
     */
    private function collectCalls(string $contents): array
    {
        $calls = [];
        $skip = ['if', 'for', 'foreach', 'while', 'switch', 'catch', 'array', 'list', 'isset', 'empty', 'unset', 'echo', 'print', 'return', 'function', 'use', 'match', 'new', 'fn'];
        if (! preg_match_all('/(?<!function\s)\b([A-Za-z_][A-Za-z0-9_]*)\s*\(/u', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($matches[1] as $hit) {
            $name = (string) $hit[0];
            if (in_array($name, $skip, true)) {
                continue;
            }
            $open = (int) $hit[1] + strlen($name);
            while (($contents[$open] ?? '') === ' ') {
                $open++;
            }
            if (($contents[$open] ?? '') !== '(') {
                continue;
            }
            $close = $this->matchingDelimiter($contents, $open, '(', ')');
            if ($close === null) {
                continue;
            }
            $calls[] = [
                'name' => $name,
                'args' => $this->splitArguments(substr($contents, $open + 1, $close - $open - 1)),
            ];
        }

        return $calls;
    }

    /**
     * @return list<string>
     */
    private function splitArguments(string $args): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($args);
        for ($i = 0; $i < $length; $i++) {
            $char = $args[$i];
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\') {
                    $buffer .= $args[++$i] ?? '';

                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;

                continue;
            }
            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
                $buffer .= $char;

                continue;
            }
            if ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
                $buffer .= $char;

                continue;
            }
            if ($char === ',' && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';

                continue;
            }
            $buffer .= $char;
        }
        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }

    private function matchingDelimiter(string $contents, int $openOffset, string $open, string $close): ?int
    {
        $length = strlen($contents);
        $depth = 0;
        $quote = null;
        for ($i = $openOffset; $i < $length; $i++) {
            $char = $contents[$i];
            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;

                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }
            if ($char === '/' && ($contents[$i + 1] ?? '') === '/') {
                $newline = strpos($contents, "\n", $i);
                $i = $newline === false ? $length : $newline;

                continue;
            }
            if ($char === '/' && ($contents[$i + 1] ?? '') === '*') {
                $end = strpos($contents, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;

                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;

                continue;
            }
            if ($char === $open) {
                $depth++;
            } elseif ($char === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function looksLikeTranslationKey(string $value): bool
    {
        if ($value === '' || str_contains($value, ' ') || str_contains($value, '$') || str_contains($value, '{')) {
            return false;
        }

        return str_contains($value, '.') || str_contains($value, '/');
    }

    /**
     * @param  array<string, string>  $wrappers
     * @param  array<string, list<array{path: string, line: int}>>  $expanded
     */
    private function collectDirectDynamicPrefixes(string $contents, string $relative, array $wrappers, array &$expanded): void
    {
        $wrappedPrefixes = array_values($wrappers);
        if (! preg_match_all(
            '/(?:__|trans(?:_choice)?|filled_trans|@lang|Lang::(?:get|has))\(\s*[\'"]([^\'"]+)[\'"]\s*\.\s*\$(\w+)/u',
            $contents,
            $matches,
            PREG_OFFSET_CAPTURE,
        )) {
            return;
        }

        foreach ($matches[1] as $index => $prefixHit) {
            $prefix = (string) $prefixHit[0];
            if (! str_ends_with($prefix, '.')) {
                $prefix .= '.';
            }
            if (in_array($prefix, $wrappedPrefixes, true)) {
                continue;
            }
            $variable = (string) $matches[2][$index][0];
            $offset = (int) $prefixHit[1];
            $items = $this->arrayStringLiterals($contents, $variable);
            if ($items === []) {
                $this->rememberOpenPrefix($prefix, $relative, $contents, $offset);
                continue;
            }
            foreach ($items as $item) {
                $this->rememberFoundKey($expanded, $prefix.$item, $relative, $contents, $offset);
            }
        }
    }

    private function collectScriptDynamicPrefixes(string $contents, string $relative): void
    {
        $patterns = [
            '/(?:\$t|\bt|i18n\.t)\(\s*`([^`$]+)\$\{/u',
            '/(?:\$t|\bt|i18n\.t)\(\s*[\'"]([^\'"]+)[\'"]\s*\+\s*/u',
        ];
        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[1] as $hit) {
                $prefix = (string) $hit[0];
                if (! str_ends_with($prefix, '.')) {
                    $prefix .= '.';
                }
                $this->rememberOpenPrefix($prefix, $relative, $contents, (int) $hit[1]);
            }
        }
    }

    private function rememberOpenPrefix(string $prefix, string $relative, string $contents, int $offset): void
    {
        $normalized = $this->normalizeKeyForLexicon(rtrim($prefix, '.'));
        if ($normalized === '') {
            return;
        }
        $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
        $this->openPrefixes[$normalized] ??= ['prefix' => $normalized, 'evidence' => []];
        $this->openPrefixes[$normalized]['evidence'][] = ['path' => $relative, 'line' => $line];
    }

    /**
     * @return list<array{path: string, line: int}>
     */
    private function evidenceForOpenPrefix(string $key): array
    {
        $candidate = strtolower($this->normalizeKeyForLexicon($key));
        foreach ($this->openPrefixes as $prefix => $entry) {
            $normalized = strtolower((string) $prefix);
            if ($candidate === $normalized || str_starts_with($candidate, $normalized.'.')) {
                return $entry['evidence'];
            }
        }

        return [];
    }

    /**
     * Same-file helpers that prepend a translation prefix (e.g. $homeText).
     *
     * @return array<string, string> variable name => raw prefix ending with a dot
     */
    public function translationPrefixWrappers(string $contents): array
    {
        $wrappers = [];
        if (! preg_match_all(
            '/\$(\w+)\s*=\s*(?:static\s+)?function\b[\s\S]{0,2000}?(?:__|trans|trans_choice|filled_trans|Lang::get)\(\s*[\'"]([^\'"$]+)[\'"]\s*\.\s*\$/u',
            $contents,
            $matches,
        )) {
            return $wrappers;
        }

        foreach ($matches[1] as $index => $name) {
            $prefix = (string) $matches[2][$index];
            if (! str_ends_with($prefix, '.')) {
                $prefix .= '.';
            }
            $wrappers[(string) $name] = $prefix;
        }

        return $wrappers;
    }

    /**
     * Message::* classes that resolve __('prefix.'.$key) for Message::get('leaf').
     *
     * @return array<string, string> class name => normalized prefix (no trailing dot)
     */
    public function messageClassPrefixes(string $contents): array
    {
        $classToPrefix = [];
        if (! preg_match('/class\\s+(\\w+Message)\\b/', $contents, $classMatch)) {
            return $classToPrefix;
        }
        if (! preg_match("/__\\(\\s*['\"]([^'\"]+)['\"]\\s*\\.\\s*\\\$key/", $contents, $prefixMatch)) {
            return $classToPrefix;
        }
        $classToPrefix[$classMatch[1]] = $this->normalizeKeyForLexicon(rtrim($prefixMatch[1], '.'));

        return $classToPrefix;
    }

    /**
     * @return list<string>
     */
    private function arrayStringLiterals(string $contents, string $variable): array
    {
        $pattern = '/\$'.preg_quote($variable, '/').'\s*=\s*\[([\s\S]*?)\]/u';
        if (! preg_match($pattern, $contents, $match)) {
            if (preg_match('/foreach\s*\(\s*\$(\w+)\s+as\s+\$'.preg_quote($variable, '/').'\b/u', $contents, $loop)) {
                return $this->arrayStringLiterals($contents, (string) $loop[1]);
            }

            return [];
        }

        if (! preg_match_all('/[\'"]([^\'"]+)[\'"]/u', $match[1], $items)) {
            return [];
        }

        return array_values(array_filter(
            $items[1],
            static fn (string $value): bool => $value !== '' && ! str_contains($value, '$') && ! str_contains($value, ' '),
        ));
    }

    /**
     * @param  array<string, list<array{path: string, line: int}>>  $found
     */
    private function rememberFoundKey(array &$found, string $key, string $relative, string $contents, int $offset): void
    {
        $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
        $found[$key] ??= [];
        $found[$key][] = ['path' => $relative, 'line' => $line];
    }

    /**
     * @param  list<string>  $roots
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function iterSourceFiles(array $roots, array $extensions): array
    {
        $files = [];
        foreach ($roots as $root) {
            $root = rtrim($root, DIRECTORY_SEPARATOR);
            if ($root === '' || ! is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                $lower = strtolower($path);
                $matchesExtension = false;
                foreach ($extensions as $extension) {
                    if (str_ends_with($lower, '.'.$extension)) {
                        $matchesExtension = true;
                        break;
                    }
                }
                if (! $matchesExtension || $this->shouldSkip($path)) {
                    continue;
                }
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * @param  array<string, list<array{path: string, line: int}>>  $foundInCode
     * @return array<string, list<array{path: string, line: int}>>
     */
    private function buildNormalizedEvidenceIndex(array $foundInCode): array
    {
        $index = [];
        foreach ($foundInCode as $rawKey => $evidence) {
            $variants = array_values(array_unique(array_filter([
                (string) $rawKey,
                $this->normalizeKeyForLexicon((string) $rawKey),
                str_replace('/', '.', (string) $rawKey),
            ])));
            foreach ($variants as $variant) {
                foreach (array_unique([$variant, strtolower($variant)]) as $indexed) {
                    $index[$indexed] = array_values(array_merge($index[$indexed] ?? [], $evidence));
                }
            }
        }

        return $index;
    }

    /**
     * Collect exact keys from nested PHP/JSON translation arrays.
     *
     * For Laravel PHP group files, pass $groupPrefix (e.g. "root/auth") so keys
     * match runtime calls like __('root/auth.sign_up.step.email.title').
     *
     * @param  list<string>  $files
     * @param  array<string, string>  $groupPrefixes  map absolute file path => group prefix
     * @return list<string>
     */
    public function collectKeysFromTranslationFiles(array $files, array $groupPrefixes = []): array
    {
        $keys = [];

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $prefix = $groupPrefixes[$file] ?? $groupPrefixes[realpath($file) ?: $file] ?? '';

            if ($extension === 'json') {
                $decoded = json_decode((string) file_get_contents($file), true);
                if (is_array($decoded)) {
                    $this->flattenKeys($decoded, $prefix, $keys);
                }

                continue;
            }

            if ($extension === 'php') {
                $data = include $file;
                if (is_array($data)) {
                    $this->flattenKeys($data, $prefix, $keys);
                }
            }
        }

        $keys = array_values(array_unique(array_filter($keys, static fn ($key) => is_string($key) && $key !== '')));
        sort($keys);

        return $keys;
    }

    /**
     * @return list<array{key: string, line: int}>
     */
    private function extractLiterals(string $contents): array
    {
        $matches = [];
        $patterns = [
            // PHP / Blade
            "/__(?:\\s*)\\(\\s*['\"]([^'\"]+)['\"]/u",
            "/@lang\\(\\s*['\"]([^'\"]+)['\"]/u",
            "/trans(?:_choice)?\\(\\s*['\"]([^'\"]+)['\"]/u",
            "/filled_trans\\(\\s*['\"]([^'\"]+)['\"]/u",
            "/Lang::(?:get|has)\\(\\s*['\"]([^'\"]+)['\"]/u",
            // JS / TS helpers
            "/\\bt\\(\\s*['\"]([^'\"]+)['\"]/u",
            "/\\bi18n\\.t\\(\\s*['\"]([^'\"]+)['\"]/u",
            "/\\buseTranslation\\([^)]*\\)[^;]*\\.t\\(\\s*['\"]([^'\"]+)['\"]/u",
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match_all($pattern, $contents, $groups, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($groups[1] as $group) {
                $key = trim((string) $group[0]);
                if ($key === '' || str_contains($key, '$') || str_contains($key, '{') || str_ends_with($key, '.')) {
                    continue;
                }
                $offset = (int) $group[1];
                $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                $matches[] = ['key' => $key, 'line' => $line];
            }
        }

        return $matches;
    }

    /**
     * @param  array<string|int, mixed>  $data
     * @param  list<string>  $keys
     */
    private function flattenKeys(array $data, string $prefix, array &$keys): void
    {
        foreach ($data as $segment => $value) {
            $next = $prefix === '' ? (string) $segment : $prefix.'.'.$segment;
            if (is_array($value)) {
                $this->flattenKeys($value, $next, $keys);

                continue;
            }
            $keys[] = $next;
        }
    }

    private function isScannable(string $path): bool
    {
        $lower = strtolower($path);
        foreach (self::EXTENSIONS as $extension) {
            if (str_ends_with($lower, '.'.$extension)) {
                return true;
            }
        }

        return false;
    }

    private function shouldSkip(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        return str_contains($normalized, '/vendor/')
            || str_contains($normalized, '/node_modules/')
            || str_contains($normalized, '/storage/')
            || str_contains($normalized, '/.git/')
            || str_contains($normalized, '/public/build/')
            || str_contains($normalized, '/.build-releases/')
            || str_contains($normalized, '/bootstrap/cache/');
    }

    private function relativePath(string $path): string
    {
        $base = base_path();
        $normalized = str_replace('\\', '/', $path);
        $baseNormalized = str_replace('\\', '/', $base);

        if (str_starts_with($normalized, $baseNormalized.'/')) {
            return substr($normalized, strlen($baseNormalized) + 1);
        }

        return $normalized;
    }
}
