<?php

namespace A21\LexiconClient\Extraction\Extractors;

use A21\LexiconClient\Extraction\ExtractedEntry;
use A21\LexiconClient\Extraction\Extractor;
use Illuminate\Support\Facades\DB;

/**
 * Base extractor for DB-backed layers (database, content). Reads rows from a
 * configurable table/connection and emits one entry per (row, field).
 *
 * Definition keys: connection, table, entity_type, id_column, code_column,
 * fields (list), where (map of column => value), area, application, module,
 * source_url (supports {id} / {code} placeholders), layer.
 */
abstract class TabularExtractor implements Extractor
{
    abstract protected function defaultLayer(): string;

    public function extract(array $definition): array
    {
        $table = (string) ($definition['table'] ?? '');

        if ($table === '') {
            return [];
        }

        $connection = $definition['connection'] ?? null;
        $idColumn = (string) ($definition['id_column'] ?? 'id');
        $codeColumn = isset($definition['code_column']) ? (string) $definition['code_column'] : null;
        $fields = array_values(array_map('strval', (array) ($definition['fields'] ?? [])));
        $where = (array) ($definition['where'] ?? []);
        $entityType = (string) ($definition['entity_type'] ?? 'record');
        $area = (string) ($definition['area'] ?? $entityType);
        $application = (string) ($definition['application'] ?? 'app');
        $module = (string) ($definition['module'] ?? $entityType);
        $layer = isset($definition['layer']) ? (string) $definition['layer'] : $this->defaultLayer();
        $sourceUrlTemplate = isset($definition['source_url']) ? (string) $definition['source_url'] : null;

        $query = DB::connection($connection)->table($table);

        foreach ($where as $column => $value) {
            if ($value === null) {
                $query->whereNull((string) $column);
            } else {
                $query->where((string) $column, $value);
            }
        }

        $entries = [];

        foreach ($query->get() as $row) {
            $row = (array) $row;
            $entityId = (string) ($row[$idColumn] ?? '');

            if ($entityId === '') {
                continue;
            }

            $code = $codeColumn !== null && isset($row[$codeColumn]) ? (string) $row[$codeColumn] : null;

            $metadata = [];
            if ($codeColumn !== null && $code !== null) {
                $metadata[$codeColumn] = $code;
            }

            foreach ($fields as $field) {
                $value = $row[$field] ?? null;

                if (! is_string($value) || $value === '') {
                    continue;
                }

                $entries[] = new ExtractedEntry(
                    areaCode: $area,
                    application: $application,
                    module: $module,
                    entityType: $entityType,
                    entityId: $entityId,
                    fieldName: $field,
                    sourceText: $value,
                    layer: $layer,
                    sourceUrl: $this->resolveSourceUrl($sourceUrlTemplate, $entityId, $code),
                    metadata: $metadata,
                );
            }
        }

        return $entries;
    }

    private function resolveSourceUrl(?string $template, string $entityId, ?string $code): ?string
    {
        if ($template === null) {
            return null;
        }

        return str_replace(['{id}', '{code}'], [$entityId, $code ?? ''], $template);
    }
}
