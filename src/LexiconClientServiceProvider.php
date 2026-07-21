<?php

namespace A21\LexiconClient;

use A21\LexiconClient\Console\ExportCommand;
use A21\LexiconClient\Console\ExtractCommand;
use A21\LexiconClient\Console\ImportCommand;
use A21\LexiconClient\Console\InitCommand;
use A21\LexiconClient\Console\PullCommand;
use A21\LexiconClient\Console\PushCommand;
use A21\LexiconClient\Console\StatusCommand;
use A21\LexiconClient\Console\SyncCommand;
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
            ]);

            $this->publishes([
                __DIR__.'/../config/lexicon.php' => config_path('lexicon.php'),
            ], 'lexicon-config');
        }
    }
}
