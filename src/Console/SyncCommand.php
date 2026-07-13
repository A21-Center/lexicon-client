<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Manifest\LexiconManifestReader;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    use EnsuresLexiconCredentials;

    protected $signature = 'lexicon:sync';

    protected $description = 'Prepare local source sync with Lexicon';

    public function handle(LexiconManifestReader $manifestReader): int
    {
        $config = $manifestReader->mergedConfig();

        if (! $this->ensureLexiconCredentials($config)) {
            return self::FAILURE;
        }

        $this->info('Sync source extraction coming soon');

        return self::SUCCESS;
    }
}
