# Extraction & Translation Layers

This guide covers the configuration‑driven extraction engine added to
`a21/lexicon-client`. It lets **any** Laravel application push its translatable
content to a central Lexicon server without writing custom code — you only
declare *extractors* in `lexicon.json`.

The engine is generic: the runner contains no application‑specific logic. Each
extractor definition is matched to an extractor by its `type` and produces
`ExtractedEntry` units that are sent to the Lexicon Integration API
(`POST /integrations/translate`).

## Translation layers

Every entry carries a **layer** that tells Lexicon how the content is sourced,
keyed and synchronised:

| Layer | Typical source | Key strategy |
|-------|----------------|--------------|
| `interface` | `lang/*.php`, `lang/*.json` | nested dotted key path |
| `template` | Blade views, email templates | `{template_path}_{segment}` |
| `database` | DB rows owned by the app | `{code\|slug\|id}_{field}` |
| `content` | CMS‑style entities | `{entity_type}_{entity_id}_{field}` |

Lexicon is the source of truth. Pushing content **never deletes** keys or
translations; when a `source_text` changes the existing translations are marked
outdated instead of being removed.

## Configuration (`lexicon.json`)

Add an `extractors` array at the project root. Each item is a definition object
whose `type` selects the extractor. `group` and `entity_type` are optional and
used by the `--group` / `--entity` CLI filters.

```json
{
  "project_code": "hub",
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

Relative `base_path` and `paths` are resolved against the app root at runtime.

### Extractor types

- **`files`** — scans `lang/` for the source locale, flattens nested keys and
  emits one entry per leaf. Keys: `base_path`, `source_language`, `formats`,
  `application`, `module`, `layer` (default `interface`).
- **`blade`** — parses `.blade.php` files for `__('…')`, `trans('…')` and
  `@lang('…')` helpers (deduplicated per file). Keys: `paths` (list), `area`,
  `application`, `module`, `layer` (default `template`).
- **`database`** / **`content`** — read rows from a configurable
  table/connection and emit one entry per (row, field). Keys: `connection`,
  `table`, `entity_type`, `id_column`, `code_column`, `fields` (list), `where`
  (map of `column => value`, `null` becomes `IS NULL`), `area`, `application`,
  `module`, `source_url` (supports `{id}` and `{code}` placeholders), `layer`.

## Commands

| Command | Description |
|---------|-------------|
| `php artisan lexicon:extract` | Read‑only preview of what the extractors produce |
| `php artisan lexicon:push` | Extract and push to Lexicon (`--dry-run` to preview) |
| `php artisan lexicon:sync` | Extract + summary + push (extract then synchronise) |

Shared options for all three:

- `--group=<name>` — limit to one or more extractor groups (repeatable).
- `--entity=<type>` — limit to one or more `entity_type` values (repeatable).
- `--all` — run every configured extractor.
- `--dry-run` — never send anything to the server.

Examples:

```bash
php artisan lexicon:extract
php artisan lexicon:push --group=categories --dry-run
php artisan lexicon:push --group=interface
php artisan lexicon:sync --entity=category
```

The push summary reports how many entries were `created` vs already `existing`,
plus any failures, and never removes server‑side data.

## Extending with a custom extractor

Implement `A21\LexiconClient\Extraction\Extractor`, register it in an
`ExtractorRegistry`, and run it through the `ExtractionRunner`:

```php
use A21\LexiconClient\Extraction\ExtractorRegistryFactory;
use A21\LexiconClient\Extraction\ExtractionRunner;

$registry = ExtractorRegistryFactory::default();
$registry->register(new MyCustomExtractor()); // ->type() must match the manifest `type`

$runner = new ExtractionRunner($registry);
$entries = $runner->run($definitions, ['groups' => ['catalog']]);
```

Each `extract()` call returns `ExtractedEntry` objects; `toTranslatePayload()`
shapes them for the Integration API.
