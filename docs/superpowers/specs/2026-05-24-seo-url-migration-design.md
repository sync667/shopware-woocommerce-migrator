# SEO URL Migration to WordPress Redirection Plugin

**Date:** 2026-05-24
**Status:** Approved for implementation planning

## Goal

Preserve public SEO of a Shopware shop being migrated to WooCommerce by reproducing all of Shopware's indexable URLs as 301 redirects on the WordPress side. Redirects are pushed to the [Redirection plugin](https://redirection.me/) via its REST API, with a portable CSV export written alongside every run as a fallback / audit artifact.

## Non-goals

- Slug-matching (forcing WooCommerce product/category slugs to equal the Shopware path). The shop accepts new slugs and bridges old → new with 301s.
- Editing `.htaccess` or nginx config. The legacy `generateApacheRedirectRule` / `generateNginxRedirectRule` helpers in `SeoUrlTransformer` are removed as part of this work.
- Managing redirects after first push. If a WP admin later edits or deletes a rule in the UI, the migrator does not reconcile — the migrator is "source of truth on first push, admin owns after".

## Scope of Shopware URLs migrated

For each language configured on the migration, all rows in Shopware's `seo_url` table where `is_deleted = 0`, for these `route_name` values:

| Shopware route                  | Entity type | WooCommerce target form               |
|---------------------------------|-------------|---------------------------------------|
| `frontend.detail.page`          | product     | `/product/{slug}/`                    |
| `frontend.navigation.page`      | category    | `/product-category/{slug}/`           |
| `frontend.cms.page%` (LIKE)     | cms page    | `/{slug}/`                            |

Both **canonical** and **non-canonical** rows are included — non-canonical aliases are exactly the URLs Google has previously indexed under renamed slugs, and dropping them is the SEO leak this work fixes.

## Components

### New: `app/Services/RedirectionClient.php`

Thin REST wrapper for the Redirection plugin at `/wp-json/redirection/v1/`, authenticated via the existing WordPress application password used by `WordPressMediaClient`.

```php
class RedirectionClient
{
    public function isAvailable(): bool;              // GET /wp-json/redirection/v1/group, true on 200
    public function ensureGroup(string $name): int;   // find by name or create; returns group id
    /** @return array<string>  source URL strings already present in the group */
    public function loadExistingSources(int $groupId): array;
    public function createRedirect(
        string $source,
        string $target,
        int $code,
        int $groupId,
    ): int;  // returns new rule id
}
```

`loadExistingSources` follows the plugin's `?page=N&per_page=200` pagination and returns the full set as a flat array, called **once per job** and cached in the calling job's memory.

### Changed: existing entity jobs

`MigrateProductJob`, `MigrateCategoriesJob`, `MigrateCmsPagesJob` must capture the slug returned by the WooCommerce / WordPress create call and persist it via `StateManager.set(..., payload: ['slug' => $slug, ...])`. The `payload` JSON column on `migration_entities` already exists; no schema change.

- Products: `slug` field on the `products` REST response.
- Categories: `slug` field on the `products/categories` REST response (already computed locally; persist the returned value to handle WC's uniqueness suffixing).
- CMS pages: `slug` from the WP `pages` REST response.

### Changed: `app/Shopware/Readers/SeoUrlReader.php`

- Remove the `is_canonical = 1` filter from `fetchAllForProducts`, `fetchAllForCategories`, `fetchAllForCmsPages`. The `is_canonical` value remains in the SELECT and is forwarded into transformer metadata.
- `fetchUpdatedSince` already lacks the canonical filter — leave as is.
- All queries continue to filter `is_deleted = 0` and `language_id = ?`.

### Changed: `app/Shopware/Transformers/SeoUrlTransformer.php`

Replace the current single-method transformer with one that builds source + target from explicit inputs:

```php
public function transform(
    object $seoUrl,
    string $entityType,            // 'product' | 'category' | 'cms_page'
    ?int $wooId,
    ?string $wooSlug,
): array {
    // returns [
    //   'source'   => normalized source path with leading slash,
    //   'target'   => target permalink, or ?p=/?cat=/?page_id= fallback if $wooSlug null,
    //   'code'     => 301,
    //   'metadata' => [shopware_id, foreign_key, route_name, is_canonical],
    // ]
}
```

Target construction:

| Entity     | With slug                       | Slug-missing fallback         |
|------------|---------------------------------|-------------------------------|
| product    | `/product/{slug}/`              | `/?p={wooId}`                 |
| category   | `/product-category/{slug}/`     | `/?cat={wooId}`               |
| cms_page   | `/{slug}/`                      | `/?page_id={wooId}`           |

Source normalization: leading slash forced, trailing slash stripped, internal `//` collapsed, query string stripped (Shopware `seo_path_info` shouldn't contain one, but defensive).

The unused `generateApacheRedirectRule` and `generateNginxRedirectRule` methods are removed.

### Changed: `app/Jobs/MigrateSeoUrlsJob.php`

Rewritten around the flow in the next section. The two near-duplicate per-route loops in the current implementation collapse into a single iterator driven by an `(entity_type, route_name)` map that also includes CMS pages.

State writes change:

- Successful pushes call `StateManager.set('seo_url', shopwareId, $ruleId, migrationId, $payload)` with the **actual Redirection rule id** returned by `createRedirect` — replacing today's `crc32($source)` synthetic id.
- All non-failure skips go through `StateManager.markSkipped('seo_url', shopwareId, migrationId, $payload)` with `payload.skip_reason` set to one of the enumerated values: `exists_in_redirection`, `dry_run`, `api_disabled`, `plugin_unavailable`, `self_redirect`, `source_collision`.
- Genuine errors (HTTP 5xx, transformer exception) call `markFailed` as today.
- Entity-not-yet-migrated is **not** a state write — the row is left untouched so a later pass picks it up.

## Data flow

```
On job start:
  groupId         = RedirectionClient.ensureGroup(config('migration.redirection.group_name'))
  existingSources = RedirectionClient.loadExistingSources(groupId)     // 1 paginated fetch, in-memory set
  csvWriter       = open storage/app/migrations/{id}/redirects.csv (append, with header on first write)

For each (entityType, routeFilter) in [
    ('product',  fetchAllForProducts),
    ('category', fetchAllForCategories),
    ('cms_page', fetchAllForCmsPages),
]:
    rows = reader.$routeFilter()                                       // canonical + aliases
    For each row:
      if CancellationService.isCancelled(migrationId): return
      if StateManager.alreadyMigrated('seo_url', row.id, migrationId): continue

      entity = StateManager row for (entityType, row.foreign_key)
      if entity == null:
          log warning "entity not yet migrated"; continue              // not marked failed; retried next pass

      data = SeoUrlTransformer.transform(row, entityType, entity.woo_id, entity.payload.slug ?? null)

      if data.source == data.target:
          log info "self-redirect"; mark seo_url skipped; continue

      if data.source in existingSources:
          mark seo_url skipped (skip_reason: exists_in_redirection); continue

      csvWriter.append([data.source, data.target, '', data.code])      // always, even on dry-run

      if migration.is_dry_run:
          mark seo_url skipped (dry_run); continue

      if not RedirectionClient.isAvailable():
          mark seo_url skipped (skip_reason: plugin_unavailable); continue

      ruleId = RedirectionClient.createRedirect(data.source, data.target, data.code, groupId)
      existingSources.add(data.source)
      StateManager.set('seo_url', row.id, ruleId, migrationId, payload: data)
      log info "migrated"

On job end:
  csvWriter.close()
  db.disconnect()
```

Delta runs (`fetchUpdatedSince`) flow through the same per-row block; the route-stream loop is the only thing that differs between full and delta runs.

## Idempotency contract

- Re-running the job is safe and produces zero new Redirection POSTs once converged.
- The in-memory `existingSources` set is seeded from the plugin (not from `MigrationEntity`), so it stays correct even if Redirection state and migrator state drift apart.
- `alreadyMigrated()` short-circuits before any HTTP call, so re-runs over the same migration are cheap.
- An admin deleting a rule in the WP UI will cause the next **full** run to recreate it (delta runs won't, because the seo_url row's `updated_at` won't have changed). Documented as expected.

## Configuration

Add to `config/migration.php`:

```php
'redirection' => [
    'enabled'      => env('MIGRATION_REDIRECTION_ENABLED', true),
    'group_name'   => env('MIGRATION_REDIRECTION_GROUP_NAME', 'Shopware Migration'),
    'default_code' => (int) env('MIGRATION_REDIRECTION_DEFAULT_CODE', 301),
],
```

When `enabled = false`, the job runs in file-only mode: CSV is written, no API calls are made, all rows are marked `skipped (skip_reason: api_disabled)`.

WordPress base URL and application password reuse the existing `WordPressMediaClient` config — no new credentials.

## Edge cases

1. **Plugin not installed** — `isAvailable()` returns false, job logs a warning and runs in file-only mode, completes successfully.
2. **Slug missing in `payload`** — falls back to `?p=` / `?cat=` / `?page_id=`. WP itself resolves these to the canonical permalink with its own 301; net chain is 2 hops. Logged at info.
3. **`source == target` after normalization** — skipped, logged. Happens when Shopware's `seo_path_info` happens to equal the WooCommerce slug path.
4. **Two SEO rows produce the same source URL after normalization** — first wins; subsequent collisions logged at warning with both `foreign_key`s for manual review. State row for the later one is marked skipped with `skip_reason: source_collision`.
5. **`foreign_key` resolves to no migrated entity** — logged at warning, state row left unmigrated (not marked failed), retried on next pass.
6. **Multi-segment Shopware CMS paths** (e.g. `/help/shipping/returns`) — preserved exactly as the redirect source; target is `/{wp_slug}/`. Redirection matches on the full path so the 301 still works.
7. **Pagination of `loadExistingSources`** — follow `?page=N&per_page=200` until response is shorter than `per_page`. Done once per job, cached.

## File export

- Path: `storage/app/migrations/{migration_id}/redirects.csv`
- Format: matches the Redirection plugin's CSV importer: header row `source,target,regex,code`, one rule per row, `regex` empty (always literal-match).
- Written on **every** run, including dry-run and plugin-unavailable. Provides:
  - A portable artifact the operator can import into Redirection by hand.
  - An audit trail of what the job intended to do, byte-comparable to a fixture in tests.

## Test plan

Per project convention: PHPUnit, feature tests preferred, `Http::fake()` for HTTP, existing Shopware DB fakes for the Shopware side. No live network calls.

### Unit

**`SeoUrlTransformerTest`**
- `transform` builds the right target for product / category / cms_page with slug present.
- `transform` falls back to `?p=` / `?cat=` / `?page_id=` when slug is null.
- Source normalization: leading slash added, trailing slash stripped, double slashes collapsed, query string stripped.
- Self-redirect detection: returns `source == target` for matching paths so the job can skip.
- Metadata block includes `shopware_id`, `foreign_key`, `route_name`, `is_canonical`.

**`RedirectionClientTest`** (with `Http::fake()`)
- `isAvailable()` returns true on 200, false on 404.
- `ensureGroup` creates when no match exists; returns existing id when one does.
- `loadExistingSources` follows pagination across multiple pages and returns the flat URL list.
- `createRedirect` POSTs the correct payload shape (`url`, `action_data.url`, `action_type: 'url'`, `action_code`, `group_id`) and returns the new rule id.
- `createRedirect` throws on 4xx with the API error message preserved.

### Feature

**`MigrateSeoUrlsJobTest`**
- **Happy path:** one product with canonical + one alias, both `foreign_key`s resolve via `StateManager`. Expect 2 Redirection POSTs with correct targets, both `seo_url` state rows marked migrated with the returned rule ids.
- **Skip-on-existing:** seed `Http::fake()` so `loadExistingSources` returns the source URL. Expect 0 POSTs, state row marked skipped with `skip_reason: exists_in_redirection`.
- **Idempotency:** run job twice over the same input. Expect second run to produce 0 POSTs (caught by `alreadyMigrated`).
- **Entity not yet migrated:** seo_url's `foreign_key` has no `MigrationEntity` row. Expect 0 POSTs, seo_url left unmigrated (not failed).
- **Slug missing fallback:** product `woo_id` present, `payload.slug` null. Expect POST with target `/?p={wooId}`.
- **Plugin unavailable:** `Http::fake` returns 404 on the group endpoint. Expect 0 POSTs, CSV still written with all rows, state rows marked skipped with `skip_reason: plugin_unavailable`.
- **API disabled:** `MIGRATION_REDIRECTION_ENABLED=false`. Expect 0 HTTP calls of any kind, CSV written, rows marked skipped with `skip_reason: api_disabled`.
- **Dry run:** `migration.is_dry_run = true`. Expect 0 POSTs, CSV written, rows marked skipped with `skip_reason: dry_run`.
- **Self-redirect:** source equals target after normalization. Expect 0 POST, row skipped with `skip_reason: self_redirect`.
- **Source collision:** two seo_url rows normalize to the same source. Expect 1 POST (first row wins), second marked skipped with `skip_reason: source_collision`.
- **Cancellation:** `CancellationService::isCancelled` returns true mid-loop. Expect clean exit with partial state persisted.
- **CSV byte-fixture:** a small input set produces a CSV file byte-identical to a checked-in fixture.

**Entity job extensions**
- `MigrateProductJobTest`: after create, `MigrationEntity.payload.slug` equals the slug from the WC response.
- `MigrateCategoriesJobTest`: same, including the case where WC suffixes the slug for uniqueness (the persisted slug must be the one WC returned, not the one we sent).
- `MigrateCmsPagesJobTest`: same for the WP pages endpoint.

## Out of scope (for follow-ups)

- A WP-CLI / artisan command to re-import the CSV without rerunning the full SEO job.
- Reverse migration (WooCommerce → Shopware redirects).
- Multi-language seo_url support beyond the single `language_id` already configured on the migration run.
