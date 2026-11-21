<?php

namespace A21\LexiconClient\Http\Controllers;

use A21\LexiconClient\Rewrite\KeyRewriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Throwable;

/**
 * Lexicon → consumer app webhook: rewrite keys in lang files + code.
 *
 * POST /api/localization/rewrite-keys
 * Authorization: Bearer {LEXICON_SYNC_SECRET}
 */
class RewriteKeysController extends Controller
{
    public function __invoke(Request $request, KeyRewriteService $service): JsonResponse
    {
        $data = $request->validate([
            'map' => ['required', 'array', 'min:1'],
            'map.*' => ['required', 'string', 'max:500'],
            'paths' => ['nullable', 'array'],
            'paths.*' => ['string', 'max:500'],
            'lang' => ['nullable', 'array'],
            'lang.*' => ['string', 'max:500'],
            'dry_run' => ['sometimes', 'boolean'],
            'do_lang' => ['sometimes', 'boolean'],
            'do_code' => ['sometimes', 'boolean'],
            'keep_aliases' => ['sometimes', 'boolean'],
            'values' => ['sometimes', 'array'],
            'values.*' => ['array'],
            'values.*.*' => ['nullable', 'string'],
        ]);

        /** @var array<string, string> $map */
        $map = [];
        foreach ($data['map'] as $from => $to) {
            $map[(string) $from] = (string) $to;
        }

        /** @var array<string, array<string, string>> $values */
        $values = [];
        foreach ((array) ($data['values'] ?? []) as $from => $localeMap) {
            if (! is_array($localeMap)) {
                continue;
            }
            foreach ($localeMap as $locale => $text) {
                if (! is_scalar($text) && $text !== null) {
                    continue;
                }
                $values[(string) $from][(string) $locale] = (string) $text;
            }
        }

        try {
            $result = $service->rewrite(
                $map,
                array_values(array_filter(array_map('strval', (array) ($data['paths'] ?? [])))),
                array_values(array_filter(array_map('strval', (array) ($data['lang'] ?? [])))),
                (bool) ($data['dry_run'] ?? false),
                array_key_exists('do_lang', $data) ? (bool) $data['do_lang'] : true,
                array_key_exists('do_code', $data) ? (bool) $data['do_code'] : true,
                array_key_exists('keep_aliases', $data) ? (bool) $data['keep_aliases'] : false,
                $values,
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Rewrite keys failed.',
                'success' => false,
                'errors' => ['Rewrite keys failed.'],
            ], 500);
        }

        return response()->json([
            'data' => $result,
        ]);
    }
}
