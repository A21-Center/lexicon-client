<?php

namespace A21\LexiconClient\Extraction\Extractors;

class ContentExtractor extends TabularExtractor
{
    public function type(): string
    {
        return 'content';
    }

    protected function defaultLayer(): string
    {
        return 'content';
    }
}
