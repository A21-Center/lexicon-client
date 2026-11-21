<?php

namespace A21\LexiconClient\Console;

use A21\LexiconClient\Support\LexiconConfigGuard;
use Illuminate\Console\Command;

trait EnsuresLexiconCredentials
{
    /**
     * @param  array<string, mixed>  $config
     */
    protected function ensureLexiconCredentials(array $config): bool
    {
        try {
            LexiconConfigGuard::assertReady($config);
        } catch (\Throwable $exception) {
            /** @var Command $this */
            $this->error($exception->getMessage());

            return false;
        }

        return true;
    }
}
