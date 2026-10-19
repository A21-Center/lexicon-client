<?php

namespace A21\LexiconClient\Http\Controllers;

use A21\LexiconClient\Scan\ScanUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Lexicon → consumer app webhook: scan key usage (lexicon:scan-usage equivalent).
 *
 * POST /api/localization/scan-usage
 * Authorization: Bearer {LEXICON_SYNC_SECRET}
 */
class ScanUsageController extends Controller
{
    public function __invoke(Request $request, ScanUsageService $service): JsonResponse
    {
        $data = $request->validate([
            'project_code' => ['nullable', 'string', 'max:120'],
            'application' => ['nullable', 'string', 'max:120'],
            'areas' => ['nullable', 'array'],
            'areas.*' => ['string', 'max:120'],
            'keys' => ['nullable', 'array'],
            'keys.*' => ['string', 'max:500'],
            'paths' => ['nullable', 'array'],
            'paths.*' => ['string', 'max:500'],
            'catalog' => ['sometimes', 'string', Rule::in(['lang', 'lexicon'])],
            'evidence_lang' => ['nullable', 'array'],
            'evidence_lang.*' => ['string', 'max:500'],
            'replace_unmentioned' => ['sometimes', 'boolean'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $service->scan([
                'project_code' => $data['project_code'] ?? null,
                'application' => $data['application'] ?? null,
                'areas' => $data['areas'] ?? [],
                'keys' => $data['keys'] ?? [],
                'paths' => $data['paths'] ?? [],
                'catalog' => $data['catalog'] ?? 'lexicon',
                'evidence_lang' => $data['evidence_lang'] ?? [],
                'replace_unmentioned' => array_key_exists('replace_unmentioned', $data)
                    ? (bool) $data['replace_unmentioned']
                    : true,
                'dry_run' => (bool) ($data['dry_run'] ?? false),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'success' => false,
                'errors' => [$exception->getMessage()],
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Scan usage failed.',
                'success' => false,
                'errors' => [$exception->getMessage()],
            ], 500);
        }

        return response()->json([
            'data' => $result,
        ]);
    }
}
