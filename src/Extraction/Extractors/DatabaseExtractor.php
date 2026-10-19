<?php

namespace A21\LexiconClient\Extraction\Extractors;

class DatabaseExtractor extends TabularExtractor
{
    public function type(): string
    {
        return 'database';
    }

    protected function defaultLayer(): string
    {
        return 'database';
    }
}
