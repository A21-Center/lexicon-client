<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Files\LexiconFileWriter;
use A21\LexiconClient\Http\LexiconHttpClient;
use A21\LexiconClient\Manifest\LexiconManifestReader;
use Illuminate\Console\Command;

class PullCommand extends Command
{
    protected $signature = 'lexicon:pull
        {--lang=* : Target language codes}
        {--area=* : Area codes}
        {--all : Use all manifest languages and areas}
        {--only-approved : Export only approved translations}
        {--format= : Export format}
        {--dry-run : Show files without writing}
        {--force : Write even when hash is unchanged}';

    protected $description = 'Pull translation files from Lexicon and write them locally';

    public function handle(LexiconManifestReader $manifestReader, LexiconFileWriter $fileWriter): int
    {
        $config = $manifestReader->mergedConfig();
        $client = new LexiconHttpClient($config);

        $languages = $this->option('all') ? $config['languages'] : ($this->option('lang') ?: $config['languages']);
        $areas = $this->option('all') ? $config['areas'] : ($this->option('area') ?: $config['areas']);
        $format = $this->option('format') ?: ($config['output']['format'] ?? 'nested_json');

        try {
            $result = $client->export([
                'project_code' => $config['project_code'],
                'environment' => $config['environment'],
                'languages' => array_values((array) $languages),
                'areas' => array_values((array) $areas),
                'format' => $format,
                'only_approved' => (bool) $this->option('only-approved'),
            ]);
        } catch (\Throwable $exception) {
            $this->error('Lexicon pull failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $written = $fileWriter->write(
            $result['files'] ?? [],
            $config['output'],
            (bool) $this->option('dry-run'),
            (bool) $this->option('force'),
        );

        if ($this->option('dry-run')) {
            $this->info('Dry run — files that would be written:');
        } else {
            $this->info('Pull completed — files written:');
        }

        foreach ($written as $path) {
            $this->line(' - '.$path);
        }

        if ($written === []) {
            $this->warn('No files changed.');
        }

        return self::SUCCESS;
    }
}
