<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Files\LexiconFileWriter;
use A21\LexiconClient\Http\LexiconHttpClient;
use A21\LexiconClient\Manifest\LexiconManifestReader;
use Illuminate\Console\Command;

class PullCommand extends Command
{
    use EnsuresLexiconCredentials;

    protected $signature = 'lexicon:pull
        {--lang=* : Target language codes}
        {--area=* : Area codes}
        {--all : Use all manifest languages and areas}
        {--only-approved : Export only approved translations}
        {--format= : Export format}
        {--dry-run : Show files without writing}
        {--force : Overwrite files even when Lexicon content hash is unchanged}
        {--full : Full sync — write every file that differs from Lexicon (first setup)}
        {--baseline : Record hashes only for local files that already match Lexicon}
        {--reset-state : Clear saved pull hashes before running}';

    protected $description = 'Pull Lexicon translations and write only areas whose Lexicon content hash changed';

    public function handle(LexiconManifestReader $manifestReader, LexiconFileWriter $fileWriter): int
    {
        $config = $manifestReader->mergedConfig();

        if (! $this->ensureLexiconCredentials($config)) {
            return self::FAILURE;
        }

        if ($this->option('reset-state')) {
            $statePath = base_path('.lexicon/pull-state.json');
            if (is_file($statePath)) {
                @unlink($statePath);
                $this->warn('Cleared pull state at '.$statePath);
            }
        }

        $client = new LexiconHttpClient($config);

        $languages = $this->option('all') ? $config['languages'] : ($this->option('lang') ?: $config['languages']);
        $areas = $this->option('all') ? $config['areas'] : ($this->option('area') ?: $config['areas']);
        $areaFilter = array_values(array_filter((array) $this->option('area')));
        $writerFormat = (string) ($config['output']['format'] ?? 'nested_json');
        $exportFormat = in_array($writerFormat, ['php', 'laravel_php', 'nested_json', 'json'], true)
            ? 'nested_json'
            : (string) ($this->option('format') ?: $writerFormat);

        if ($this->option('format')) {
            $exportFormat = (string) $this->option('format');
            if (in_array($exportFormat, ['php', 'laravel_php'], true)) {
                $exportFormat = 'nested_json';
            }
        }

        try {
            $result = $client->export([
                'project_code' => $config['project_code'],
                'environment' => $config['environment'],
                'languages' => array_values((array) $languages),
                'areas' => array_values((array) $areas),
                'format' => $exportFormat,
                'only_approved' => (bool) $this->option('only-approved'),
            ]);
        } catch (\Throwable $exception) {
            $this->error('Lexicon pull failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $allowWithoutState = (bool) $this->option('full')
            || (bool) $this->option('force')
            || $areaFilter !== [];

        $state = \A21\LexiconClient\Files\PullStateStore::defaultPath();
        if ($state->hashes() === [] && ! $allowWithoutState && ! $this->option('baseline')) {
            $this->error('No pull state yet. Refusing a mass rewrite of lang/.');
            $this->line('First setup: php artisan lexicon:pull --full');
            $this->line('Or one area:  php artisan lexicon:pull --area=domains.artworks');
            $this->line('Or baseline:  php artisan lexicon:pull --baseline  (then pull again after Lexicon edits)');

            return self::FAILURE;
        }

        $outcome = $fileWriter->write(
            $result['files'] ?? [],
            $config['output'],
            (bool) $this->option('dry-run'),
            (bool) $this->option('force'),
            baseline: (bool) $this->option('baseline'),
            allowWithoutState: $allowWithoutState,
        );

        $written = $outcome['written'];
        $skipped = $outcome['skipped'];

        if ($this->option('baseline')) {
            $this->info(sprintf(
                'Baseline recorded — %d Lexicon file hash(es) saved, 0 written.',
                $skipped,
            ));

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                'Dry run — %d file(s) would be written, %d unchanged:',
                count($written),
                $skipped,
            ));
        } else {
            $this->info(sprintf(
                'Pull completed — %d written, %d unchanged:',
                count($written),
                $skipped,
            ));
        }

        foreach ($written as $path) {
            $this->line(' - '.$path);
        }

        if ($written === [] && $skipped === 0) {
            $this->warn('No files returned by Lexicon.');
        } elseif ($written === []) {
            $this->warn('No files changed.');
        }

        return self::SUCCESS;
    }
}
