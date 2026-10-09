<?php

namespace A21\LexiconClient\Http\Controllers;

use A21\LexiconClient\Pull\PullLangFilesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Lexicon → consumer app webhook: write lang/ files (lexicon:pull equivalent).
 *
 * POST /api/localization/pull-lang
 * Authorization: Bearer {LEXICON_SYNC_SECRET}
 */
class PullLangFilesController extends Controller
{
    public function __invoke(Request $request, PullLangFilesService $service): JsonResponse
    {
        $data = $request->validate([
            'project_code' => ['nullable', 'string', 'max:120'],
            'areas' => ['nullable', 'array'],
            'areas.*' => ['string', 'max:120'],
            'languages' => ['nullable', 'array'],
            'languages.*' => ['string', 'max:16'],
            'only_approved' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', 'string', Rule::in(['incremental', 'full', 'area'])],
            'replace' => ['sometimes', 'boolean'],
            'dry_run' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $service->pull($data);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'success' => false,
                'files_written' => [],
                'skipped' => 0,
                'errors' => [$exception->getMessage()],
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Pull lang files failed.',
                'success' => false,
                'files_written' => [],
                'skipped' => 0,
                'errors' => [$exception->getMessage()],
            ], 500);
        }

        return response()->json([
            'data' => $result,
        ]);
    }
}
