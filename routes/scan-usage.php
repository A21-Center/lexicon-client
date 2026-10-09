<?php

use A21\LexiconClient\Http\Controllers\ScanUsageController;
use A21\LexiconClient\Http\Middleware\VerifyLexiconSyncSecret;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Lexicon → app: scan usage webhook
|--------------------------------------------------------------------------
|
| Loaded when config('lexicon.scan_usage.enabled') is true.
| Lexicon should POST here with Bearer LEXICON_SYNC_SECRET.
|
*/

Route::post('localization/scan-usage', ScanUsageController::class)
    ->middleware(VerifyLexiconSyncSecret::class)
    ->name('lexicon.localization.scan-usage');
