<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Extraction\ExtractedEntry;
use A21\LexiconClient\Extraction\ExtractionRunner;
use A21\LexiconClient\Extraction\ExtractorRegistryFactory;
use A21\LexiconClient\Http\LexiconHttpClient;
use Illuminate\Console\Command;

trait InteractsWithExtractors
{
    /**
     * @return array{groups: list<string>, entities: list<string>}
     */
    protected function extractionFilters(): array
    {
        return [
            'groups' => array_values(array_filter(array_map('strval', (array) $this->option('group')))),
            'entities' => array_values(array_filter(array_map('strval', (array) $this->option('entity')))),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<ExtractedEntry>
     */
    protected function runExtraction(array $config): array
    {
        $definitions = $this->resolveDefinitionPaths(array_values((array) ($config['extractors'] ?? [])));

        $runner = new ExtractionRunner(ExtractorRegistryFactory::default());

        return $runner->run($definitions, $this->extractionFilters());
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     * @return list<array<string, mixed>>
     */
    protected function resolveDefinitionPaths(array $definitions): array
    {
        return array_map(function ($definition) {
            if (! is_array($definition)) {
                return $definition;
            }

            if (isset($definition['base_path']) && is_string($definition['base_path'])) {
                $definition['base_path'] = $this->absolutePath($definition['base_path']);
            }

            if (isset($definition['paths']) && is_array($definition['paths'])) {
                $definition['paths'] = array_map(fn ($path): string => $this->absolutePath((string) $path), $definition['paths']);
            }

            return $definition;
        }, $definitions);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<ExtractedEntry>  $entries
     */
    protected function pushExtractedEntries(array $config, array $entries): int
    {
        /** @var Command $this */
        $projectCode = (string) ($config['project_code'] ?? '');
        $client = new LexiconHttpClient($config);

        $created = 0;
        $existing = 0;
        $failed = 0;

        foreach ($entries as $entry) {
            try {
                $result = $client->translate($entry->toTranslatePayload($projectCode));

                if (($result['status'] ?? null) === 'created') {
                    $created++;
                } else {
                    $existing++;
                }
            } catch (\Throwable $exception) {
                $failed++;
                $this->warn('Push failed for '.$entry->areaCode.'/'.$entry->fieldName.': '.$exception->getMessage());
            }
        }

        $this->info('Pushed '.count($entries).' entrie(s): '.$created.' created, '.$existing.' existing, '.$failed.' failed.');

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param  list<ExtractedEntry>  $entries
     */
    protected function summarizeEntries(array $entries): void
    {
        /** @var Command $this */
        $byLayer = [];
        $byArea = [];

        foreach ($entries as $entry) {
            $layer = $entry->layer ?? 'null';
            $byLayer[$layer] = ($byLayer[$layer] ?? 0) + 1;
            $byArea[$entry->areaCode] = ($byArea[$entry->areaCode] ?? 0) + 1;
        }

        $this->line('Entries: '.count($entries));

        foreach ($byLayer as $layer => $count) {
            $this->line('  layer '.$layer.': '.$count);
        }

        foreach ($byArea as $area => $count) {
            $this->line('  area '.$area.': '.$count);
        }
    }

    private function absolutePath(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
