<?php

namespace A21\LexiconClient\Tests;

use A21\LexiconClient\Pull\PullLangFilesService;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;

class PullLangFilesServiceTest extends TestCase
{
    public function test_area_mode_requires_areas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mode area requires at least one area code.');

        (new PullLangFilesService())->pull([
            'mode' => 'area',
            'project_code' => 'studio',
            'areas' => [],
            'languages' => ['en'],
        ]);
    }

    public function test_invalid_mode_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid pull mode');

        (new PullLangFilesService())->pull([
            'mode' => 'explode',
            'project_code' => 'studio',
        ]);
    }
}
