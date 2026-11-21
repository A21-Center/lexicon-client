<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Files\LexiconFileWriter;
use A21\LexiconClient\Http\LexiconHttpClient;
use A21\LexiconClient\Manifest\LexiconManifestReader;
use A21\LexiconClient\Pull\PullLangFilesService;
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
        {--replace : For PHP files, overwrite existing keys (default only adds missing keys)}
        {--baseline : Record hashes only for local files that already match Lexicon}
        {--reset-state : Clear saved pull hashes before running}';

    protected $description = 'Pull Lexicon translations and write only areas whose Lexicon content hash changed';

    public function handle(
        LexiconManifestReader $manifestReader,
        LexiconFileWriter $fileWriter,
        PullLangFilesService $pullService,
    ): int {
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

        // Baseline is CLI-only (records hashes without writing).
        if ($this->option('baseline')) {
            return $this->runBaseline($config, $fileWriter);
        }

        $areaFilter = array_values(array_filter(array_map('strval', (array) $this->option('area'))));
        $languages = $this->option('all')
            ? array_values((array) ($config['languages'] ?? []))
            : ($this->option('lang')
                ? array_values(array_filter(array_map('strval', (array) $this->option('lang'))))
                : array_values((array) ($config['languages'] ?? [])));
        $areas = $this->option('all')
            ? array_values((array) ($config['areas'] ?? []))
            : ($areaFilter !== []
                ? $areaFilter
                : array_values((array) ($config['areas'] ?? [])));

        $mode = 'incremental';
        if ($this->option('full')) {
            $mode = 'full';
        } elseif ($areaFilter !== []) {
            $mode = 'area';
        }

        try {
            $result = $pullService->pull([
                'project_code' => $config['project_code'] ?? null,
                'areas' => $areas,
                'languages' => $languages,
                'only_approved' => (bool) $this->option('only-approved'),
                'mode' => $mode,
                'replace' => (bool) $this->option('replace'),
                'dry_run' => (bool) $this->option('dry-run'),
                'force' => (bool) $this->option('force'),
            ]);
        } catch (\Throwable $exception) {
            $this->error('Lexicon pull failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $written = $result['files_written'];
        $skipped = $result['skipped'];

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

    /**
     * @param  array<string, mixed>  $config
     */
    private function runBaseline(array $config, LexiconFileWriter $fileWriter): int
    {
        $client = new LexiconHttpClient($config);
        $writerFormat = (string) ($config['output']['format'] ?? 'nested_json');
        $exportFormat = in_array($writerFormat, ['php', 'laravel_php', 'nested_json', 'json'], true)
            ? 'nested_json'
            : $writerFormat;

        try {
            $result = $client->export([
                'project_code' => $config['project_code'],
                'environment' => $config['environment'],
                'languages' => array_values((array) ($config['languages'] ?? [])),
                'areas' => array_values((array) ($config['areas'] ?? [])),
                'format' => $exportFormat,
                'only_approved' => (bool) $this->option('only-approved'),
            ]);
        } catch (\Throwable $exception) {
            $this->error('Lexicon pull failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $outcome = $fileWriter->write(
            $result['files'] ?? [],
            $config['output'] ?? [],
            dryRun: false,
            force: false,
            baseline: true,
            allowWithoutState: true,
        );

        $this->info(sprintf(
            'Baseline recorded — %d Lexicon file hash(es) saved, 0 written.',
            $outcome['skipped'],
        ));

        return self::SUCCESS;
    }
}
