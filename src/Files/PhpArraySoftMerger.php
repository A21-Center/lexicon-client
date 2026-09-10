<?php

namespace A21\LexiconClient\Files;

class PhpArraySoftMerger
{
    /**
     * Keep existing values; only add keys / nested branches present in $incoming.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function addMissing(array $existing, array $incoming): array
    {
        $result = $existing;

        foreach ($incoming as $key => $value) {
            $stringKey = (string) $key;

            if (! array_key_exists($stringKey, $result)) {
                $result[$stringKey] = $value;

                continue;
            }

            if (is_array($value) && is_array($result[$stringKey])) {
                $result[$stringKey] = $this->addMissing($result[$stringKey], $value);
            }
        }

        return $result;
    }

    /**
     * Overwrite overlapping keys with Lexicon values; keep local-only keys.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function replace(array $existing, array $incoming): array
    {
        $result = $existing;

        foreach ($incoming as $key => $value) {
            $stringKey = (string) $key;

            if (is_array($value) && isset($result[$stringKey]) && is_array($result[$stringKey])) {
                $result[$stringKey] = $this->replace($result[$stringKey], $value);

                continue;
            }

            $result[$stringKey] = $value;
        }

        return $result;
    }

    /**
     * Dotted leaf paths present in $incoming but missing from $existing.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function missingLeaves(array $existing, array $incoming, string $prefix = ''): array
    {
        $missing = [];

        foreach ($incoming as $key => $value) {
            $stringKey = (string) $key;
            $path = $prefix === '' ? $stringKey : $prefix.'.'.$stringKey;

            if (is_array($value)) {
                if (! isset($existing[$stringKey]) || ! is_array($existing[$stringKey])) {
                    $missing = array_merge($missing, $this->flattenLeaves($value, $path));
                } else {
                    $missing = array_merge($missing, $this->missingLeaves($existing[$stringKey], $value, $path));
                }

                continue;
            }

            if (! array_key_exists($stringKey, $existing)) {
                $missing[$path] = $value;
            }
        }

        return $missing;
    }

    /**
     * Missing leaves that may be soft-injected:
     * - non-empty Lexicon values only
     * - under an already-existing top-level branch (or new top-level leaf keys)
     * Intermediate parents like artwork.ok may be created; brand-new roots (filters.*) are skipped.
     *
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public function missingLeavesWithExistingParents(array $existing, array $incoming): array
    {
        $filtered = [];

        foreach ($this->missingLeaves($existing, $incoming) as $path => $value) {
            if (! $this->hasMeaningfulValue($value)) {
                continue;
            }

            if ($this->rootBranchAllowsLeaf($existing, (string) $path)) {
                $filtered[$path] = $value;
            }
        }

        return $filtered;
    }

    private function hasMeaningfulValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        // Arrays should already be flattened to leaves before this filter runs.
        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * Allow nested keys under an existing root array (artwork.ok.c_bon).
     * Skip brand-new top-level trees (filters.panel.title when filters is absent).
     * New top-level leaf keys (foo => "bar") are allowed.
     */
    private function rootBranchAllowsLeaf(array $existing, string $path): bool
    {
        $keys = explode('.', $path);

        if (count($keys) === 1) {
            return true;
        }

        $root = $keys[0];

        return isset($existing[$root]) && is_array($existing[$root]);
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function flattenLeaves(array $content, string $prefix = ''): array
    {
        $leaves = [];

        foreach ($content as $key => $value) {
            $stringKey = (string) $key;
            $path = $prefix === '' ? $stringKey : $prefix.'.'.$stringKey;

            if (is_array($value)) {
                $leaves = array_merge($leaves, $this->flattenLeaves($value, $path));
            } else {
                $leaves[$path] = $value;
            }
        }

        return $leaves;
    }
}
