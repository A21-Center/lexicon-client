<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Http\LexiconHttpClient;
use A21\LexiconClient\Import\TranslationFileScanner;
use A21\LexiconClient\Import\TranslationImportPayloadBuilder;
use A21\LexiconClient\Manifest\LexiconManifestReader;
use Illuminate\Console\Command;

class ImportCommand extends Command
{
    protected $signature = 'lexicon:import
        {--path= : Local base path to scan}
        {--locale=* : Limit to locales}
        {--area=* : Limit to area codes}
        {--dry-run : Analyse without sending to server}
        {--force : Upsert existing values}
        {--format=auto : php|json|auto}';

    protected $description = 'Import existing local translation files into Lexicon';

    public function handle(LexiconManifestReader $manifestReader): int
    {
        $config = $manifestReader->mergedConfig();
        $importConfig = (array) ($config['import'] ?? []);

        $pathOption = $this->option('path');
        $basePath = is_string($pathOption) && $pathOption !== ''
            ? $pathOption
            : (string) ($importConfig['base_path'] ?? 'lang');

        if (! str_starts_with($basePath, '/') && ! preg_match('/^[A-Za-z]:\\\\/', $basePath)) {
            $basePath = base_path($basePath);
        }

        $format = (string) $this->option('format');
        $formats = $format === 'auto'
            ? (array) ($importConfig['formats'] ?? ['php', 'json'])
            : [$format];

        $strategy = $this->option('force') ? 'upsert' : (string) ($importConfig['default_strategy'] ?? 'create_only');

        try {
            $scanner = new TranslationFileScanner();
            $files = $scanner->scan(
                basePath: $basePath,
                formats: array_values(array_map('strval', $formats)),
                exclude: array_values(array_map('strval', (array) ($importConfig['exclude'] ?? ['vendor', 'node_modules', '.git']))),
                localesFilter: array_values(array_filter((array) $this->option('locale'))),
                areasFilter: array_values(array_filter((array) $this->option('area'))),
            );

            $built = (new TranslationImportPayloadBuilder())->build(
                $files,
                $config,
                $strategy,
                $format,
                (string) ($importConfig['base_path'] ?? 'lang'),
            );
        } catch (\Throwable $exception) {
            $this->error('Lexicon import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $stats = $built['stats'];

        if ($this->option('dry-run')) {
            $this->info('Lexicon Import Dry Run');
            $this->line('');
            $this->line('Base path: '.(string) ($importConfig['base_path'] ?? 'lang'));
            $this->line('Locales detected: '.implode(', ', (array) $stats['locales']));
            $this->line('Files detected: '.$stats['files']);
            $this->line('Areas detected: '.count((array) $stats['areas']));
            $this->line('Sources detected: '.$stats['sources']);
            $this->line('Keys detected: '.$stats['keys']);
            $this->line('Translations detected: '.$stats['translations']);
            $this->line('Warnings: '.$stats['warnings']);
            $this->line('');
            $this->info('No data sent to Lexicon server.');

            return self::SUCCESS;
        }

        try {
            $client = new LexiconHttpClient($config);
            $result = $client->import($built['payload']);
        } catch (\Throwable $exception) {
            $this->error('Lexicon import failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $summary = $result['summary'] ?? [];
        $this->info('Import '.($result['import_id'] ?? '?').' '.$result['status']);
        $this->line('Areas created: '.($summary['areas_created'] ?? 0));
        $this->line('Sources created: '.($summary['sources_created'] ?? 0));
        $this->line('Keys created: '.($summary['keys_created'] ?? 0));
        $this->line('Keys updated: '.($summary['keys_updated'] ?? 0));
        $this->line('Translations created: '.($summary['translations_created'] ?? 0));
        $this->line('Translations updated: '.($summary['translations_updated'] ?? 0));
        $this->line('Warnings: '.($summary['warnings_count'] ?? 0));

        foreach (($result['warnings'] ?? []) as $warning) {
            $this->warn(($warning['type'] ?? 'warning').': '.($warning['message'] ?? json_encode($warning)));
        }

        return self::SUCCESS;
    }
}
