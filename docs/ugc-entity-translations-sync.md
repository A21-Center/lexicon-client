# UGC entity translations & Lexicon → app sync

This package can provision the **consumer-app** side of Lexicon Google MT / UGC:

1. Table `entity_translations` (Gallery / public reads)
2. Endpoint `POST /api/localization/sync` (Lexicon webhook)
3. Shared secret `LEXICON_SYNC_SECRET`

Lexicon server remains the editing / MT source of truth for **all translation
layers** (`content`, `interface`, `database`, `template`). Google MT suggestions
can be generated and accepted on any layer. The outbound UGC sync webhook below
is primarily used for CMS-style `content` / entity fields that consumers mirror
locally. After accept / edit / approve / force retranslate, Lexicon POSTs into
this app.

## Quick install

```bash
composer require a21/lexicon-client:^1.3
php artisan lexicon:install-ugc-sync --migrate
```

Or manually:

```bash
php artisan vendor:publish --tag=lexicon-config
php artisan vendor:publish --tag=lexicon-ugc-migrations
php artisan migrate
```

## Environment

```env
# Existing: app → Lexicon
LEXICON_API_URL=https://lexicon.example.com
LEXICON_CLIENT_CODE=my-app
LEXICON_PROJECT_CODE=my-app
LEXICON_CLIENT_SECRET=lex_sk_live_xxxxxxxx

# New: Lexicon → app (UGC sync webhook)
LEXICON_SYNC_SECRET=shared-outbound-secret
LEXICON_UGC_SYNC_ENABLED=true
# optional route prefix (default api → /api/localization/sync)
# LEXICON_UGC_SYNC_PREFIX=api
```

Two secrets:

| Secret | Direction | Where |
|--------|-----------|--------|
| `LEXICON_CLIENT_SECRET` | App → Lexicon | Lexicon `integration_clients` inbound secret |
| `LEXICON_SYNC_SECRET` | Lexicon → App | Lexicon `integration_clients.sync_secret` (same value) |

## Lexicon server configuration

On the Lexicon integration client for this app:

- `sync_url` = `https://your-api.example.com/api/localization/sync`
- `sync_secret` = same as `LEXICON_SYNC_SECRET`

## Contract

```http
POST /api/localization/sync
Authorization: Bearer <LEXICON_SYNC_SECRET>
Content-Type: application/json

{
  "entity_type": "artworks",
  "entity_id": "uuid-or-string",
  "field": "title",
  "locale": "en",
  "value": "Hello",
  "needs_review": false,
  "origin": "google_mt",
  "locked": false,
  "source_hash": "…"
}
```

Behaviour: **upsert** on `(entity_type, entity_id, field, locale)`.

## Gallery rule

Public surfaces should read **approved-only** rows (`needs_review = false`) and
fall back to the source column when no approved translation exists.

## A21 note

A21 already has a historical `entity_translations` table and its own sync
controller. Publishing the package migration is safe: if the table exists, it
only adds missing `origin` / `locked` / `source_hash` columns. Prefer A21’s
native endpoint if already wired; use the package route for greenfield SaaS apps.
