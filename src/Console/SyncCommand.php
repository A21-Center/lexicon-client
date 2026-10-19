<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Manifest\LexiconManifestReader;
use Illuminate\Console\Command;

class SyncCommand extends Command
{
    use EnsuresLexiconCredentials;
    use InteractsWithExtractors;

    protected $signature = 'lexicon:sync
        {--group=* : Limit to extractor groups}
        {--entity=* : Limit to entity types}
        {--all : Run every configured extractor}
        {--dry-run : Extract and preview without sending to Lexicon}';

    protected $description = 'Extract configured sources and synchronise them with Lexicon (extract + push)';

    public function handle(LexiconManifestReader $manifestReader): int
    {
        $config = $manifestReader->mergedConfig();

        if (! $this->ensureLexiconCredentials($config)) {
            return self::FAILURE;
        }

        if (($config['extractors'] ?? []) === []) {
            $this->warn('No extractors defined in lexicon.json.');

            return self::SUCCESS;
        }

        try {
            $entries = $this->runExtraction($config);
        } catch (\Throwable $exception) {
            $this->error('Lexicon sync failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Lexicon sync');
        $this->summarizeEntries($entries);

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing sent to Lexicon.');

            return self::SUCCESS;
        }

        return $this->pushExtractedEntries($config, $entries);
    }
}
