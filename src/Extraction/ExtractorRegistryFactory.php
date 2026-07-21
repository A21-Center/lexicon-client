<?php

namespace A21\LexiconClient\Extraction;

use A21\LexiconClient\Extraction\Extractors\BladeExtractor;
use A21\LexiconClient\Extraction\Extractors\ContentExtractor;
use A21\LexiconClient\Extraction\Extractors\DatabaseExtractor;
use A21\LexiconClient\Extraction\Extractors\FilesExtractor;

class ExtractorRegistryFactory
{
    public static function default(): ExtractorRegistry
    {
        $registry = new ExtractorRegistry();
        $registry->register(new FilesExtractor());
        $registry->register(new BladeExtractor());
        $registry->register(new DatabaseExtractor());
        $registry->register(new ContentExtractor());

        return $registry;
    }
}
