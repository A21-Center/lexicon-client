# a21/lexicon-client

Laravel package to connect a client application (Hub, Studio, Gallery…) to a central **Lexicon** translation server.

## Requirements

- PHP 8.1+
- Laravel 10.x

## Install (Packagist)

```bash
composer require a21/lexicon-client
php artisan vendor:publish --tag=lexicon-config
php artisan lexicon:init
```

## Configuration

Add to `.env` (never commit secrets):

```env
LEXICON_API_URL=https://lexicon.a21.com
LEXICON_CLIENT_CODE=hub
LEXICON_PROJECT_CODE=hub
LEXICON_CLIENT_SECRET=lex_sk_live_xxxxxxxx
LEXICON_ENVIRONMENT=production
```

Optional manifest at project root: `lexicon.json` (no secret in this file).

## Commands

| Command | Description |
|---------|-------------|
| `php artisan lexicon:init` | Create `lexicon.json` + append `.env.example` |
| `php artisan lexicon:status` | Check server connection and project metadata |
| `php artisan lexicon:export` | Request export bundle from server |
| `php artisan lexicon:import` | Scan local `lang/` files and import into Lexicon |
| `php artisan lexicon:pull` | Write only files whose Lexicon content hash changed |
| `php artisan lexicon:sync` | Placeholder (source scan coming later) |

Examples:

```bash
php artisan lexicon:status
php artisan lexicon:import --path=lang --dry-run
php artisan lexicon:import --path=lang
php artisan lexicon:pull --baseline
php artisan lexicon:pull
php artisan lexicon:pull --area=domains.artworks
php artisan lexicon:pull --replace --area=domains.artworks
php artisan lexicon:pull --lang=fr --area=catalog --only-approved --dry-run
```

Prefer `lexicon:pull` **without** `--force`. PHP output defaults to `merge=add_missing`: only **new non-empty leaf keys under parents that already exist** are injected (blank placeholders like `''` are skipped; comments, order, and local values stay; brand-new branches are skipped). Use `--replace` (or `output.merge=replace`) to overwrite/sync full trees from Lexicon. After restoring `lang/` from git, run `--area=…` or `--baseline` as needed. Use `--force` only to rewrite even when Lexicon content is unchanged.

## Server setup

Create an integration client on the Lexicon server (admin API). Copy the secret **once**, then configure this package.

See the Lexicon server docs: `docs/client-package.md`, `docs/client-package-js.md` (npm), `docs/integration-api.md`, `docs/export-cli.md`.

## Security

- Keep `LEXICON_CLIENT_SECRET` server-side only (`.env`).
- Never put the secret in `lexicon.json` or frontend code.

## License

MIT
