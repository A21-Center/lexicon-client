<?php

namespace A21\LexiconClient;

use A21\LexiconClient\Console\ExportCommand;
use A21\LexiconClient\Console\ExtractCommand;
use A21\LexiconClient\Console\ImportCommand;
use A21\LexiconClient\Console\InitCommand;
use A21\LexiconClient\Console\InstallUgcSyncCommand;
use A21\LexiconClient\Console\PullCommand;
use A21\LexiconClient\Console\PushCommand;
use A21\LexiconClient\Console\ScanUsageCommand;
use A21\LexiconClient\Console\StatusCommand;
use A21\LexiconClient\Console\SyncCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LexiconClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lexicon.php', 'lexicon');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InitCommand::class,
                StatusCommand::class,
                ExportCommand::class,
                ImportCommand::class,
                PullCommand::class,
                SyncCommand::class,
                ExtractCommand::class,
                PushCommand::class,
                ScanUsageCommand::class,
                InstallUgcSyncCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/lexicon.php' => config_path('lexicon.php'),
            ], 'lexicon-config');

            $this->publishes([
                __DIR__.'/../database/migrations/2026_09_06_000000_create_entity_translations_table.php' => database_path('migrations/2026_09_06_000000_create_entity_translations_table.php'),
            ], 'lexicon-ugc-migrations');
        }

        $this->bootUgcSyncRoutes();
        $this->bootPullLangRoutes();
        $this->bootScanUsageRoutes();
    }

    private function bootUgcSyncRoutes(): void
    {
        if (! filter_var(config('lexicon.ugc_sync.enabled', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $prefix = trim((string) config('lexicon.ugc_sync.prefix', 'api'), '/');

        Route::middleware('api')
            ->prefix($prefix)
            ->group(__DIR__.'/../routes/ugc-sync.php');
    }

    private function bootPullLangRoutes(): void
    {
        if (! filter_var(config('lexicon.pull_lang.enabled', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $prefix = trim((string) config('lexicon.pull_lang.prefix', 'api'), '/');

        Route::middleware('api')
            ->prefix($prefix)
            ->group(__DIR__.'/../routes/pull-lang.php');
    }

    private function bootScanUsageRoutes(): void
    {
        if (! filter_var(config('lexicon.scan_usage.enabled', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $prefix = trim((string) config('lexicon.scan_usage.prefix', 'api'), '/');

        Route::middleware('api')
            ->prefix($prefix)
            ->group(__DIR__.'/../routes/scan-usage.php');
    }
}
