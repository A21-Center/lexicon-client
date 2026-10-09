<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Manifest\LexiconManifestReader;
use A21\LexiconClient\Scan\ScanUsageService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ScanUsageCommand extends Command
{
    use EnsuresLexiconCredentials;

    protected $signature = 'lexicon:scan-usage
        {--application= : Application code (studio|hub|gallery|custom)}
        {--project= : Override LEXICON_PROJECT_CODE for this run}
        {--areas=* : Limit catalog / replace_unmentioned to these area codes}
        {--path=* : Extra roots to scan (defaults: resources, app, src)}
        {--lang=* : Translation file/dir globs for exact catalog keys}
        {--catalog=lexicon : Catalog source: lang (local files) or lexicon (project full_keys)}
        {--evidence-lang=* : Lang files/dirs whose keys count as Used (e.g. Studio lang/cs for classification)}
        {--dry-run : Scan and print summary without posting to Lexicon}
        {--no-replace-unmentioned : Do not mark Lexicon keys absent from catalog as unused}';

    protected $description = 'Scan exact translation key literals in client code and report Used/Unused to Lexicon.';

    public function handle(ScanUsageService $service, LexiconManifestReader $manifestReader): int
    {
        $config = $manifestReader->mergedConfig();
        $projectOverride = trim((string) $this->option('project'));
        if ($projectOverride !== '') {
            $config['project_code'] = $projectOverride;
        }

        if (! $this->option('dry-run') && ! $this->ensureLexiconCredentials($config)) {
            return self::FAILURE;
        }

        try {
            $summary = $service->scan([
                'application' => $this->option('application') ?: null,
                'project_code' => $projectOverride !== '' ? $projectOverride : null,
                'areas' => array_values(array_filter(array_map('strval', (array) $this->option('areas')))),
                'paths' => array_values(array_filter(array_map('strval', (array) $this->option('path')))),
                'lang' => array_values(array_filter(array_map('strval', (array) $this->option('lang')))),
                'catalog' => (string) ($this->option('catalog') ?: 'lexicon'),
                'evidence_lang' => array_values(array_filter(array_map('strval', (array) $this->option('evidence-lang')))),
                'replace_unmentioned' => ! $this->option('no-replace-unmentioned'),
                'dry_run' => (bool) $this->option('dry-run'),
            ]);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Scan usage failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Scanned catalog=%d area(s)=%s',
            (int) ($summary['catalog_count'] ?? 0),
            $summary['areas'] !== [] ? implode(',', $summary['areas']) : 'all',
        ));
        $this->line('Used: '.(int) ($summary['used'] ?? 0));
        $this->line('Unused: '.(int) ($summary['unused'] ?? 0));

        if (! empty($summary['dry_run'])) {
            $this->comment('Dry-run: report not sent.');

            return self::SUCCESS;
        }

        $this->info('Lexicon usage report accepted.');
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');

        return self::SUCCESS;
    }
}