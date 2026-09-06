<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Http\LexiconHttpClient;
use A21\LexiconClient\Manifest\LexiconManifestReader;
use Illuminate\Console\Command;

class ExportCommand extends Command
{
    use EnsuresLexiconCredentials;

    protected $signature = 'lexicon:export
        {--lang=* : Target language codes}
        {--area=* : Area codes}
        {--only-approved : Export only approved translations}
        {--format=nested_json : Export format}';

    protected $description = 'Request an export bundle from the Lexicon server';

    public function handle(LexiconManifestReader $manifestReader): int
    {
        $config = $manifestReader->mergedConfig();

        if (! $this->ensureLexiconCredentials($config)) {
            return self::FAILURE;
        }

        $client = new LexiconHttpClient($config);

        $languages = $this->option('lang') ?: $config['languages'];
        $areas = $this->option('area') ?: $config['areas'];

        try {
            $result = $client->export([
                'project_code' => $config['project_code'],
                'environment' => $config['environment'],
                'languages' => array_values((array) $languages),
                'areas' => array_values((array) $areas),
                'format' => (string) $this->option('format'),
                'only_approved' => (bool) $this->option('only-approved'),
            ]);
        } catch (\Throwable $exception) {
            $this->error('Lexicon export failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Export '.$result['export_id'].' completed with '.count($result['files'] ?? []).' files.');

        return self::SUCCESS;
    }
}
