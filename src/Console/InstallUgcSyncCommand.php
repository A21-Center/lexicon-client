<?php

namespace A21\LexiconClient\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Publish UGC sync artefacts for consumer apps (Gallery / public UGC).
 */
class InstallUgcSyncCommand extends Command
{
    protected $signature = 'lexicon:install-ugc-sync
        {--migrate : Run migrations after publishing}';

    protected $description = 'Publish entity_translations migration + enable Lexicon→app UGC sync endpoint';

    public function handle(): int
    {
        $this->call('vendor:publish', [
            '--tag' => 'lexicon-ugc-migrations',
            '--force' => true,
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'lexicon-config',
            '--force' => false,
        ]);

        $this->appendEnvKeys();

        $this->info('UGC sync scaffolding published.');
        $this->line('1. Set LEXICON_SYNC_SECRET in .env (same value as Lexicon integration_clients.sync_secret).');
        $this->line('2. Set LEXICON_UGC_SYNC_ENABLED=true');
        $this->line('3. On Lexicon, set integration_clients.sync_url to https://<your-api>/api/localization/sync');
        $this->line('4. Run: php artisan migrate');

        if ($this->option('migrate')) {
            $this->call('migrate', ['--force' => true]);
        }

        return self::SUCCESS;
    }

    private function appendEnvKeys(): void
    {
        $keys = <<<'ENV'

# Lexicon UGC sync (Lexicon → this app)
LEXICON_SYNC_SECRET=
LEXICON_UGC_SYNC_ENABLED=false
ENV;

        foreach (['.env.example', '.env'] as $relative) {
            $path = base_path($relative);
            if (! is_file($path)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (str_contains($contents, 'LEXICON_SYNC_SECRET')) {
                continue;
            }
            File::append($path, PHP_EOL.trim($keys).PHP_EOL);
            $this->info("Appended UGC sync keys to {$relative}");
        }
    }
}
