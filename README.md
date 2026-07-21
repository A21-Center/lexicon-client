# a21/lexicon-client

Laravel package to connect a client application (Hub, Studio, Gallery, or **any**
Laravel app) to a central **Lexicon** translation server.

Lexicon is a SaaS-style translation source of truth. This package lets an app:

- **import / pull** interface translations (`lang/*.php`, `lang/*.json`),
- **extract & push** translatable content from files, Blade templates and the
  database using configuration-only *extractors*,
- do all of it through Artisan commands, with the secret kept server-side.

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Environment variables](#environment-variables)
- [The `lexicon.json` manifest](#the-lexiconjson-manifest)
- [Authentication & HTTP](#authentication--http)
- [Artisan commands](#artisan-commands)
- [Translation layers](#translation-layers)
- [Extractors](#extractors)
- [Scheduling](#scheduling)
- [Programmatic usage](#programmatic-usage)
- [Security](#security)
- [Troubleshooting](#troubleshooting)

## Requirements

- PHP 8.1+
- Laravel 10.x, 11.x or 12.x (`illuminate/*` `^10|^11|^12`)

## Installation

```bash
composer require a21/lexicon-client:^1.2
```

The service provider is auto-discovered via Laravel package discovery
(`extra.laravel.providers`), so no manual registration is needed. If you disable
auto-discovery, register it manually in `config/app.php` (or
`bootstrap/providers.php` on Laravel 11+):

```php
A21\LexiconClient\LexiconClientServiceProvider::class,
```

Publish the config file and scaffold the manifest:

```bash
php artisan vendor:publish --tag=lexicon-config   # -> config/lexicon.php
php artisan lexicon:init                           # -> lexicon.json + .env.example keys
```

## Configuration

`config/lexicon.php` (published) reads everything from the environment:

```php
return [
    'api_url'      => env('LEXICON_API_URL'),
    'client_code'  => env('LEXICON_CLIENT_CODE'),
    'project_code' => env('LEXICON_PROJECT_CODE'),
    'secret'       => env('LEXICON_CLIENT_SECRET'),
    'environment'  => env('LEXICON_ENVIRONMENT', env('APP_ENV', 'local')),

    'manifest' => base_path('lexicon.json'),

    'output' => [
        'base_path' => env('LEXICON_OUTPUT_BASE_PATH', 'lang'),
        'pattern'   => env('LEXICON_OUTPUT_PATTERN', '{locale}/{relative_path}'),
        'format'    => env('LEXICON_OUTPUT_FORMAT', 'php'),
        'merge'     => env('LEXICON_OUTPUT_MERGE', 'add_missing'),
    ],

    'http' => [
        'timeout'        => 30,
        'import_timeout' => 180,
        'retry_times'    => 2,
        'retry_sleep_ms' => 200,
    ],
];
```

| Key | Purpose |
|-----|---------|
| `api_url` | Base URL of the Lexicon server (the client calls `{api_url}/api/lexicon`). |
| `client_code` | Integration client identifier (falls back to `lexicon.json → client`). |
| `project_code` | Target project (falls back to `lexicon.json → project`). |
| `secret` | Integration client secret — **server-side only**. |
| `environment` | Logical environment sent to Lexicon (`local`/`develop`/`production`). |
| `manifest` | Absolute path to `lexicon.json` (defaults to the project root). |
| `output.*` | How pulled translations are written back to `lang/`. |
| `http.*` | Timeouts and retry policy for outbound requests. |

## Environment variables

Add to `.env` (never commit secrets):

```env
LEXICON_API_URL=https://lexicon.a21.com
LEXICON_CLIENT_CODE=hub
LEXICON_PROJECT_CODE=hub
LEXICON_CLIENT_SECRET=lex_sk_live_xxxxxxxx
LEXICON_ENVIRONMENT=production

# optional output overrides
LEXICON_OUTPUT_BASE_PATH=lang
LEXICON_OUTPUT_FORMAT=php
LEXICON_OUTPUT_MERGE=add_missing
```

Get `LEXICON_CLIENT_SECRET` by creating an integration client on the Lexicon
server (admin API); the secret is shown **once**.

## The `lexicon.json` manifest

A non-secret file at the project root, versioned with your app. It is merged
with `config/lexicon.php` at runtime (`config` wins for credentials; the
manifest provides `client`/`project` fallbacks plus `languages`, `areas`,
`output`, `import` and `extractors`).

```json
{
  "client": "hub",
  "project": "hub",
  "environment": "production",
  "languages": ["fr", "ar", "de"],
  "areas": ["catalog", "settings", "emails"],
  "import": {
    "base_path": "lang",
    "source_language": "en",
    "formats": ["php", "json"]
  },
  "output": {
    "format": "php",
    "base_path": "lang",
    "pattern": "{locale}/{relative_path}"
  },
  "extractors": []
}
```

> Never put `LEXICON_CLIENT_SECRET` in `lexicon.json`.

## Authentication & HTTP

All requests go to `{api_url}/api/lexicon/...` with these headers (built by
`LexiconHttpClient`):

| Header | Value |
|--------|-------|
| `Authorization` | `Bearer <secret>` |
| `X-Lexicon-Client` | `client_code` |
| `X-Lexicon-Project` | `project_code` |
| `X-Lexicon-Environment` | `environment` |
| `Accept` | `application/json` |

Requests use the configured `timeout`, and automatically retry `retry_times`
on `5x` server errors. Endpoints used: `/client/status`, `/client/export`,
`/client/import`, `/integrations/translate`.

## Artisan commands

| Command | Description |
|---------|-------------|
| `php artisan lexicon:init` | Create `lexicon.json` and append keys to `.env.example`. |
| `php artisan lexicon:status` | Check server connection and project metadata. |
| `php artisan lexicon:export` | Request an export bundle from the server. |
| `php artisan lexicon:import` | Scan local `lang/` files and import into Lexicon. |
| `php artisan lexicon:pull` | Write only files whose Lexicon content hash changed. |
| `php artisan lexicon:extract` | Preview translatable content from configured extractors (read-only). |
| `php artisan lexicon:push` | Extract and push translatable content to Lexicon. |
| `php artisan lexicon:sync` | Extract configured sources and synchronise them with Lexicon. |

### Import / pull (interface layer)

```bash
php artisan lexicon:status
php artisan lexicon:import --path=lang --dry-run
php artisan lexicon:import --path=lang
php artisan lexicon:pull --baseline
php artisan lexicon:pull --area=domains.artworks
php artisan lexicon:pull --replace --area=domains.artworks
php artisan lexicon:pull --lang=fr --area=catalog --only-approved --dry-run
```

Prefer `lexicon:pull` **without** `--force`. PHP output defaults to
`merge=add_missing`: only **new non-empty leaf keys under existing top-level
branches** are injected. Use `--replace` (or `output.merge=replace`) to
overwrite full trees from Lexicon. Use `--force` only to rewrite even when the
Lexicon content is unchanged.

### Extract / push / sync (all layers)

Shared options: `--group=<name>` (repeatable), `--entity=<type>` (repeatable),
`--all`, `--dry-run`.

```bash
php artisan lexicon:extract                          # read-only preview
php artisan lexicon:push --group=categories --dry-run
php artisan lexicon:push --group=categories
php artisan lexicon:sync --entity=category
```

The push summary reports `created` vs already `existing` entries and any
failures. Pushing **never deletes** server-side keys or translations.

## Translation layers

Every pushed entry carries a **layer** describing how it is sourced and keyed:

| Layer | Typical source | Key strategy |
|-------|----------------|--------------|
| `interface` | `lang/*.php`, `lang/*.json` | nested dotted key path |
| `template` | Blade views / email templates | `{template_path}_{segment}` |
| `database` | DB rows owned by the app | `{code\|slug\|id}_{field}` |
| `content` | CMS-style entities | `{entity_type}_{entity_id}_{field}` |

## Extractors

Declare an `extractors` array in `lexicon.json`. Each item is matched to an
extractor by `type`. `group` and `entity_type` power the `--group`/`--entity`
filters. Relative `base_path` / `paths` are resolved against the app root.

```json
{
  "extractors": [
    {
      "group": "interface",
      "type": "files",
      "layer": "interface",
      "application": "hub-backend",
      "module": "interface",
      "base_path": "lang",
      "source_language": "en",
      "formats": ["php", "json"]
    },
    {
      "group": "emails",
      "type": "blade",
      "layer": "template",
      "application": "hub-backend",
      "module": "emails",
      "area": "emails",
      "paths": ["resources/views/emails"]
    },
    {
      "group": "categories",
      "type": "database",
      "layer": "database",
      "application": "hub-backend",
      "module": "cs",
      "area": "categories",
      "entity_type": "category",
      "connection": null,
      "table": "categories",
      "id_column": "id",
      "code_column": "code",
      "fields": ["name", "description"],
      "where": { "deleted_at": null },
      "source_url": "/crm/classifications/categories/{id}"
    }
  ]
}
```

| Type | Reads | Key definition fields |
|------|-------|-----------------------|
| `files` | `lang/` for the source locale (flattened) | `base_path`, `source_language`, `formats` |
| `blade` | `.blade.php` — `__()`, `trans()`, `@lang()` (deduped) | `paths[]`, `area` |
| `database` / `content` | rows from a DB table via `Illuminate\Support\Facades\DB` | `connection`, `table`, `id_column`, `code_column`, `fields[]`, `where{}`, `source_url` |

The `database`/`content` extractors use your configured Laravel database
connections. `connection: null` uses the default connection; set it to any name
from `config/database.php`. `where` maps `column => value` (a `null` value
becomes `WHERE column IS NULL`). `source_url` supports `{id}` and `{code}`
placeholders so translators can jump back to the source screen.

See [docs/extraction-and-layers.md](docs/extraction-and-layers.md) for the full
reference and a custom-extractor guide.

## Scheduling

Run extraction on a schedule (e.g. keep DB content in sync nightly). In
`app/Console/Kernel.php` (Laravel 10) or `routes/console.php` (Laravel 11+):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('lexicon:push --group=categories')->dailyAt('02:00');
Schedule::command('lexicon:import --path=lang')->weekly();
```

## Programmatic usage

Call the extraction engine or HTTP client directly from your own services:

```php
use A21\LexiconClient\Extraction\ExtractorRegistryFactory;
use A21\LexiconClient\Extraction\ExtractionRunner;
use A21\LexiconClient\Http\LexiconHttpClient;

$registry = ExtractorRegistryFactory::default();
$registry->register(new MyCustomExtractor()); // ->type() matches manifest `type`

$runner  = new ExtractionRunner($registry);
$entries = $runner->run($definitions, ['groups' => ['catalog']]);

$client = new LexiconHttpClient([
    'api_url'      => config('lexicon.api_url'),
    'client_code'  => config('lexicon.client_code'),
    'project_code' => config('lexicon.project_code'),
    'secret'       => config('lexicon.secret'),
    'environment'  => config('lexicon.environment'),
]);

foreach ($entries as $entry) {
    $client->translate($entry->toTranslatePayload(config('lexicon.project_code')));
}
```

A custom extractor only implements `A21\LexiconClient\Extraction\Extractor`
(`type()` + `extract(array $definition): array` returning `ExtractedEntry[]`).

## Server setup

Create an integration client on the Lexicon server (admin API), copy the secret
**once**, then configure this package. See the Lexicon server docs:
`docs/client-package.md`, `docs/client-package-js.md` (npm),
`docs/integration-api.md`, `docs/export-cli.md`.

## Security

- Keep `LEXICON_CLIENT_SECRET` server-side only (`.env`), never in
  `lexicon.json` or any frontend code.
- Frontends must call **your** backend, which then calls Lexicon with the secret.

## Troubleshooting

- **`Lexicon configuration is incomplete`** — set `LEXICON_API_URL`,
  `LEXICON_CLIENT_CODE`, `LEXICON_PROJECT_CODE`, `LEXICON_CLIENT_SECRET`.
- **`No extractors defined in lexicon.json`** — add an `extractors` array to the
  manifest, or run the file-based commands (`lexicon:import`/`lexicon:pull`).
- **`No extractor registered for type: X`** — the `type` in a definition must be
  one of `files`, `blade`, `database`, `content`, or a custom registered type.
- **401/403** — the secret/client/project headers don't match a Lexicon
  integration client with access to the project.

## License

MIT
