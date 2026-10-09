<?php

use A21\LexiconClient\Http\Controllers\PullLangFilesController;
use A21\LexiconClient\Http\Middleware\VerifyLexiconSyncSecret;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Lexicon → app: pull lang files webhook
|--------------------------------------------------------------------------
|
| Loaded when config('lexicon.pull_lang.enabled') is true.
| Lexicon should POST here with Bearer LEXICON_SYNC_SECRET.
|
*/

Route::post('localization/pull-lang', PullLangFilesController::class)
    ->middleware(VerifyLexiconSyncSecret::class)
    ->name('lexicon.localization.pull-lang');
