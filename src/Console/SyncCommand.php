<?php

namespace A21\LexiconClient\Console;

use Illuminate\Console\Command;

class SyncCommand extends Command
{
    protected $signature = 'lexicon:sync';

    protected $description = 'Prepare local source sync with Lexicon';

    public function handle(): int
    {
        $this->info('Sync source extraction coming soon');

        return self::SUCCESS;
    }
}
