<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Manifest\LexiconManifestReader;
use Illuminate\Console\Command;

class ExtractCommand extends Command
{
    use EnsuresLexiconCredentials;
    use InteractsWithExtractors;

    protected $signature = 'lexicon:extract
        {--group=* : Limit to extractor groups}
        {--entity=* : Limit to entity types}
        {--all : Run every configured extractor}
        {--dry-run : Preview only (extraction is always read-only)}';

    protected $description = 'Extract translatable content from configured extractors (read-only preview)';

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

        $this->info('Lexicon extraction');
        $this->summarizeEntries($entries);

        return self::SUCCESS;
    }
}
