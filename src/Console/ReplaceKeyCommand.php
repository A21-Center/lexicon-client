<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Rewrite\KeyRewriteService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class ReplaceKeyCommand extends Command
{
    protected $signature = 'lexicon:replace-key
        {--from= : Full key or leaf to retire}
        {--to= : Full key or leaf to keep}
        {--map=* : Extra mappings as from:to}
        {--path=* : Extra code roots to scan}
        {--lang=* : Lang file/dir roots}
        {--dry-run : Preview without writing}
        {--no-lang : Skip lang file rewrites}
        {--no-code : Skip source code rewrites}';

    protected $description = 'Replace translation keys in lang files and exact code literals (FROM → TO).';

    public function handle(KeyRewriteService $service): int
    {
        try {
            $map = $this->buildMap();
            $summary = $service->rewrite(
                $map,
                array_values(array_filter(array_map('strval', (array) $this->option('path')))),
                array_values(array_filter(array_map('strval', (array) $this->option('lang')))),
                (bool) $this->option('dry-run'),
                ! $this->option('no-lang'),
                ! $this->option('no-code'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Replace key failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Map: '.json_encode($summary['map'], JSON_UNESCAPED_SLASHES));
        $this->line('Lang files touched: '.count($summary['lang']));
        foreach ($summary['lang'] as $row) {
            $this->line(sprintf('  - %s (%d)', $row['path'], $row['replacements']));
        }
        $this->line('Code files touched: '.count($summary['code']));
        foreach ($summary['code'] as $row) {
            $this->line(sprintf('  - %s (%d)', $row['path'], $row['replacements']));
        }

        if ($summary['skipped'] !== []) {
            $this->comment('Skipped: '.implode(', ', $summary['skipped']));
        }

        if ($summary['dry_run']) {
            $this->comment('Dry-run: no files written.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function buildMap(): array
    {
        $map = [];
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        if ($from !== '' && $to !== '') {
            $map[$from] = $to;
        }

        foreach ((array) $this->option('map') as $pair) {
            $pair = trim((string) $pair);
            if ($pair === '' || ! str_contains($pair, ':')) {
                continue;
            }
            [$left, $right] = array_map('trim', explode(':', $pair, 2));
            if ($left !== '' && $right !== '') {
                $map[$left] = $right;
            }
        }

        if ($map === []) {
            throw new InvalidArgumentException('Provide --from/--to and/or --map=from:to.');
        }

        return $map;
    }
}
