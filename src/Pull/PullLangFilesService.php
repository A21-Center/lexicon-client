<?php

namespace A21\LexiconClient\Pull;

use A21\LexiconClient\Files\LexiconFileWriter;
use A21\LexiconClient\Files\PullStateStore;
use A21\LexiconClient\Http\LexiconHttpClient;
use A21\LexiconClient\Manifest\LexiconManifestReader;
use InvalidArgumentException;
use RuntimeException;

class PullLangFilesService
{
    public function __construct(
        private readonly LexiconManifestReader $manifestReader = new LexiconManifestReader(),
        private readonly LexiconFileWriter $fileWriter = new LexiconFileWriter(),
    ) {}

    /**
     * @param  array{
     *   project_code?: string|null,
     *   areas?: list<string>|null,
     *   languages?: list<string>|null,
     *   only_approved?: bool,
     *   mode?: string,
     *   replace?: bool,
     *   dry_run?: bool,
     *   force?: bool,
     * }  $options
     * @return array{
     *   success: bool,
     *   mode: string,
     *   files_written: list<string>,
     *   skipped: int,
     *   errors: list<string>
     * }
     */
    public function pull(array $options = []): array
    {
        $config = $this->manifestReader->mergedConfig();
        $mode = strtolower((string) ($options['mode'] ?? 'incremental'));
        if (! in_array($mode, ['incremental', 'full', 'area'], true)) {
            throw new InvalidArgumentException('Invalid pull mode. Use incremental, full, or area.');
        }

        $areas = array_values(array_filter(array_map('strval', (array) ($options['areas'] ?? []))));
        $languages = array_values(array_filter(array_map('strval', (array) ($options['languages'] ?? []))));

        if ($mode === 'area' && $areas === []) {
            throw new InvalidArgumentException('Mode area requires at least one area code.');
        }

        if ($languages === []) {
            $languages = array_values((array) ($config['languages'] ?? []));
        }

        $exportAreas = $areas !== []
            ? $areas
            : array_values((array) ($config['areas'] ?? []));

        $projectCode = (string) ($options['project_code'] ?? $config['project_code'] ?? '');
        if ($projectCode === '') {
            throw new InvalidArgumentException('project_code is required.');
        }

        $writerFormat = (string) ($config['output']['format'] ?? 'nested_json');
        $exportFormat = in_array($writerFormat, ['php', 'laravel_php', 'nested_json', 'json'], true)
            ? 'nested_json'
            : $writerFormat;

        $client = new LexiconHttpClient($config);

        try {
            $result = $client->export([
                'project_code' => $projectCode,
                'environment' => $config['environment'] ?? null,
                'languages' => $languages,
                'areas' => $exportAreas,
                'format' => $exportFormat,
                'only_approved' => (bool) ($options['only_approved'] ?? false),
            ]);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Lexicon pull failed: '.$exception->getMessage(), 0, $exception);
        }

        $allowWithoutState = $mode === 'full' || $mode === 'area' || (bool) ($options['force'] ?? false);
        $state = PullStateStore::defaultPath();

        if ($state->hashes() === [] && ! $allowWithoutState) {
            throw new RuntimeException(
                'No pull state yet. Use mode=full for first setup, or mode=area with areas[].'
            );
        }

        $outputConfig = $config['output'] ?? [];
        if (! empty($options['replace'])) {
            $outputConfig['merge'] = 'replace';
        }

        $outcome = $this->fileWriter->write(
            $result['files'] ?? [],
            $outputConfig,
            (bool) ($options['dry_run'] ?? false),
            (bool) ($options['force'] ?? false),
            baseline: false,
            allowWithoutState: $allowWithoutState,
        );

        return [
            'success' => true,
            'mode' => $mode,
            'files_written' => $outcome['written'],
            'skipped' => (int) $outcome['skipped'],
            'errors' => [],
        ];
    }
}
