<?php

namespace A21\LexiconClient\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates Lexicon → app outbound sync requests (Bearer LEXICON_SYNC_SECRET).
 */
class VerifyLexiconSyncSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('lexicon.sync_secret', '');
        if ($expected === '') {
            abort(503, 'Lexicon sync secret is not configured.');
        }

        $token = $request->bearerToken();
        if (! is_string($token) || ! hash_equals($expected, $token)) {
            abort(401, 'Invalid Lexicon sync secret.');
        }

        return $next($request);
    }
}
