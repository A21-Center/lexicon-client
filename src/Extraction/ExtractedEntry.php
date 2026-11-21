<?php

namespace A21\LexiconClient\Extraction;

/**
 * A single translatable unit produced by an extractor, shaped to be pushed to
 * the Lexicon Integration translate endpoint.
 */
class ExtractedEntry
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $areaCode,
        public readonly string $application,
        public readonly string $module,
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly string $fieldName,
        public readonly string $sourceText,
        public readonly ?string $layer = null,
        public readonly ?string $sourceLanguage = null,
        public readonly ?string $sourceUrl = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toTranslatePayload(string $projectCode): array
    {
        return array_filter([
            'project_code' => $projectCode,
            'area_code' => $this->areaCode,
            'application' => $this->application,
            'module' => $this->module,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'field_name' => $this->fieldName,
            'source_text' => $this->sourceText,
            'source_language' => $this->sourceLanguage,
            'source_url' => $this->sourceUrl,
            'layer' => $this->layer,
            'metadata' => $this->metadata !== [] ? $this->metadata : null,
        ], static fn ($value): bool => $value !== null);
    }
}
