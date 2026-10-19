<?php

namespace A21\LexiconClient\Extraction;

use RuntimeException;

/**
 * Runs the extractor definitions declared in lexicon.json (`extractors: [...]`)
 * through the registry, applying optional group/entity filters. The runner is
 * generic: no module-specific logic lives here.
 */
class ExtractionRunner
{
    public function __construct(
        private readonly ExtractorRegistry $registry,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $definitions
     * @param  array{groups?: list<string>, entities?: list<string>}  $filters
     * @return list<ExtractedEntry>
     */
    public function run(array $definitions, array $filters = []): array
    {
        $entries = [];

        foreach ($definitions as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            if (! $this->matchesFilters($definition, $filters)) {
                continue;
            }

            $type = (string) ($definition['type'] ?? '');
            $extractor = $this->registry->get($type);

            if (! $extractor) {
                throw new RuntimeException("No extractor registered for type: {$type}");
            }

            foreach ($extractor->extract($definition) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     * @param  array{groups?: list<string>, entities?: list<string>}  $filters
     * @return list<array<string, mixed>>
     */
    public function selectDefinitions(array $definitions, array $filters = []): array
    {
        return array_values(array_filter(
            $definitions,
            fn ($definition): bool => is_array($definition) && $this->matchesFilters($definition, $filters),
        ));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array{groups?: list<string>, entities?: list<string>}  $filters
     */
    private function matchesFilters(array $definition, array $filters): bool
    {
        $groups = $filters['groups'] ?? [];
        $entities = $filters['entities'] ?? [];

        if ($groups !== [] && ! in_array((string) ($definition['group'] ?? ''), $groups, true)) {
            return false;
        }

        if ($entities !== [] && ! in_array((string) ($definition['entity_type'] ?? ''), $entities, true)) {
            return false;
        }

        return true;
    }
}
