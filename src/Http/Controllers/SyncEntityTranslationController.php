<?php

namespace A21\LexiconClient\Http\Controllers;

use A21\LexiconClient\Models\EntityTranslation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Lexicon → consumer app webhook: upsert entity_translations.
 *
 * POST /api/localization/sync
 * Authorization: Bearer {LEXICON_SYNC_SECRET}
 */
class SyncEntityTranslationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string', 'max:80'],
            'entity_id' => ['required', 'string', 'max:64'],
            'field' => ['required', 'string', 'max:60'],
            'locale' => ['required', 'string', 'max:8'],
            'value' => ['required', 'string'],
            'needs_review' => ['sometimes', 'boolean'],
            'origin' => ['nullable', 'string', 'max:32'],
            'locked' => ['sometimes', 'boolean'],
            'source_hash' => ['nullable', 'string', 'max:64'],
        ]);

        $entity = EntityTranslation::query()->updateOrCreate(
            [
                'entity_type' => $data['entity_type'],
                'entity_id' => $data['entity_id'],
                'field' => $data['field'],
                'locale' => $data['locale'],
            ],
            [
                'value' => $data['value'],
                'needs_review' => (bool) ($data['needs_review'] ?? false),
                'origin' => $data['origin'] ?? null,
                'locked' => (bool) ($data['locked'] ?? false),
                'source_hash' => $data['source_hash'] ?? null,
            ],
        );

        return response()->json([
            'data' => [
                'id' => $entity->id,
                'entity_type' => $entity->entity_type,
                'entity_id' => $entity->entity_id,
                'field' => $entity->field,
                'locale' => $entity->locale,
                'value' => $entity->value,
                'needs_review' => (bool) $entity->needs_review,
                'origin' => $entity->origin,
                'locked' => (bool) $entity->locked,
                'source_hash' => $entity->source_hash,
            ],
        ]);
    }
}
