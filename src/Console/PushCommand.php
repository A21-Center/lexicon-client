<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Manifest\LexiconManifestReader;
use Illuminate\Console\Command;

class PushCommand extends Command
{
    use EnsuresLexiconCredentials;
    use InteractsWithExtractors;

    protected $signature = 'lexicon:push
        {--group=* : Limit to extractor groups}
        {--entity=* : Limit to entity types}
        {--all : Run every configured extractor}
        {--dry-run : Extract and preview without sending to Lexicon}';

    protected $description = 'Extract translatable content and push it to the Lexicon Integration API';

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
            $this->error('Lexicon extraction failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Lexicon push dry run (nothing sent)');
            $this->summarizeEntries($entries);

            return self::SUCCESS;
        }

        return $this->pushExtractedEntries($config, $entries);
    }
}
