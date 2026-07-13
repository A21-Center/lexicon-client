<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Http\LexiconHttpClient;
use A21\LexiconClient\Manifest\LexiconManifestReader;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    use EnsuresLexiconCredentials;

    protected $signature = 'lexicon:status';

    protected $description = 'Check Lexicon server connectivity and project metadata';

    public function handle(LexiconManifestReader $manifestReader): int
    {
        $config = $manifestReader->mergedConfig();

        if (! $this->ensureLexiconCredentials($config)) {
            return self::FAILURE;
        }

        $client = new LexiconHttpClient($config);

        try {
            $status = $client->status();
        } catch (\Throwable $exception) {
            $this->error('Lexicon connection failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Connected to Lexicon');
        $this->line('Client: '.($status['client'] ?? $config['client_code']));
        $this->line('Project: '.($status['project'] ?? $config['project_code']));
        $this->line('Environment: '.($status['environment'] ?? $config['environment']));

        if (! empty($status['languages'])) {
            $this->line('Languages: '.collect($status['languages'])->pluck('code')->implode(', '));
        }

        if (! empty($status['areas'])) {
            $this->line('Areas: '.collect($status['areas'])->pluck('code')->implode(', '));
        }

        if (! empty($status['last_export'])) {
            $this->line('Last export: '.($status['last_export']['id'] ?? 'n/a').' ('.($status['last_export']['status'] ?? 'unknown').')');
        }

        return self::SUCCESS;
    }
}
