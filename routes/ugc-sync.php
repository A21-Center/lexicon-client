<?php

use A21\LexiconClient\Http\Controllers\SyncEntityTranslationController;
use A21\LexiconClient\Http\Middleware\VerifyLexiconSyncSecret;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Lexicon UGC outbound sync (Lexicon → this app)
|--------------------------------------------------------------------------
|
| Loaded automatically when config('lexicon.ugc_sync.enabled') is true.
| Lexicon integration_clients.sync_url should point here, e.g.:
|   https://your-api.example.com/api/localization/sync
|
*/

Route::post('localization/sync', SyncEntityTranslationController::class)
    ->middleware(VerifyLexiconSyncSecret::class)
    ->name('lexicon.localization.sync');
