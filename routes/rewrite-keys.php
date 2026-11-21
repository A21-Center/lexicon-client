<?php

use A21\LexiconClient\Http\Controllers\RewriteKeysController;
use A21\LexiconClient\Http\Middleware\VerifyLexiconSyncSecret;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Lexicon → app: rewrite keys webhook
|--------------------------------------------------------------------------
|
| Loaded when config('lexicon.rewrite_keys.enabled') is true.
| Lexicon should POST here with Bearer LEXICON_SYNC_SECRET.
|
*/

Route::post('localization/rewrite-keys', RewriteKeysController::class)
    ->middleware(['throttle:30,1', VerifyLexiconSyncSecret::class])
    ->name('lexicon.localization.rewrite-keys');
