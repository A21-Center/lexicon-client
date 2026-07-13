<?php

namespace A21\LexiconClient\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InitCommand extends Command
{
    protected $signature = 'lexicon:init';

    protected $description = 'Create lexicon.json and append Lexicon variables to .env.example';

    public function handle(): int
    {
        $manifestPath = base_path('lexicon.json');

        if (! is_file($manifestPath)) {
            File::put($manifestPath, File::get(__DIR__.'/../../stubs/lexicon.json.stub'));
            $this->info('Created lexicon.json');
        } else {
            $this->warn('lexicon.json already exists.');
        }

        $envExample = base_path('.env.example');
        $stub = trim(File::get(__DIR__.'/../../stubs/env.stub'));

        if (is_file($envExample) && ! str_contains((string) file_get_contents($envExample), 'LEXICON_API_URL')) {
            File::append($envExample, PHP_EOL.PHP_EOL.$stub.PHP_EOL);
            $this->info('Appended Lexicon variables to .env.example');
        }

        $this->line('Copy LEXICON_* into your project .env.');
        $this->line('Set LEXICON_CLIENT_SECRET manually from Lexicon → Integration Clients.');
        $this->line('Commands (status/import/export/pull) will refuse to run without that secret.');
        $this->line('Never commit secrets.');

        return self::SUCCESS;
    }
}
