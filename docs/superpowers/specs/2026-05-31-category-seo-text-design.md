# Category SEO Text Migration — Design

## Goal

Migrate the long-form SEO copy that Shopware 6 shops display on category pages
(typically below the product grid) into WooCommerce so themes can render it on
`product_cat` archives.

## Background

Shopware 6 stores category copy in several places — the codebase currently
reads only one (`category_translation.description`) and only emits two WC
fields (`name`, `description`). The fields holding the long-form "SEO text
below products" are:

1. `category.cms_page_id` → `cms_page` → `cms_section` → `cms_block` →
   `cms_slot` (type `text`/`html`) → `cms_slot_translation.config` JSON
   (`content.value`). This is the Shopware-6-native location (Storefront
   layout slots), used by most modern shops.
2. `category_translation.custom_fields` (JSON). Custom-fields set by shop
   developers; the key name varies per shop. Used by shops that set up
   a dedicated SEO-text custom field.

Meta title and meta description are already read from
`category_translation.meta_title`/`meta_description` but are never written —
fixed in this round as the easy win.

## Decisions

**Target field:** WooCommerce native term description (`product_cat.description`).
Themes/SEO plugins (Yoast/RankMath) render it on the category archive page out
of the box. No new plugin required.

**Composition:** The Shopware "short" description (already migrated) is kept
as the primary text. Any resolved SEO long text is appended after it, separated
by a comment marker so a re-run can detect and replace it idempotently:

```
<existing description>

<!-- shopware-seo-text -->
<resolved long text>
```

The marker also lets shop owners visually distinguish the two on the WP admin
edit-term screen.

**Translations:** Default language only (matches operator decision). The
existing reader already filters by `language_id`.

**Yoast SEO term meta:** Continue the unconditional emit-meta_data pattern from
`ProductTransformer` (rows are inert when Yoast isn't installed). Already done
this round for `meta_title`/`meta_description`.

## Resolution order (per category)

The job tries each source in priority order. First non-empty result wins. No
concatenation across sources — that produces unpredictable output for shops
that have data in multiple places.

1. **Custom-fields key.** Operator can configure
   `migration.category_seo.custom_field_key` (default
   `custom_seo_text_below`). If the JSON value is a non-empty string, use it.
2. **CMS slot text.** If `category.cms_page_id` is set, fetch the linked CMS
   page's text slots and pick the last one (Shopware shops typically place
   the SEO long-text block at the bottom of the layout). If the operator
   wants a different selection rule a later iteration can add a config knob.
3. **None.** Skip the append, log nothing. Most categories don't have SEO
   long text and that's normal.

## Components

**CategoryReader (extend)**
- SELECT additional columns: `LOWER(HEX(c.cms_page_id)) AS cms_page_id`,
  `ct.custom_fields AS translation_custom_fields`.
- Apply to both `fetchAll` and `fetchUpdatedSince`.

**CategorySeoTextResolver (new service, `app/Services/`)**
- Constructor: `ShopwareDB $db`.
- Method `resolve(object $category): string` — returns the SEO long text or
  `''`. Encapsulates the priority-order logic and the CMS slot query so
  CategoryReader stays simple.
- Reads `cms_slot` joined to `cms_slot_translation` filtered by language; picks
  text-type slots and extracts `content.value` from the JSON `config`.

**CategoryTransformer (extend)**
- Already receives the raw `$category` object. Accept the resolved text as a
  second optional argument: `transform(object $category, ?int $wooParentId, string $seoTextBelow = ''): array`.
- If `$seoTextBelow !== ''`, append it to `description` with the marker.

**MigrateCategoriesJob (extend)**
- Inject `CategorySeoTextResolver` (already follows DI pattern).
- For each category: `$seoText = $resolver->resolve($category); $data = $transformer->transform($category, $wooParentId, $seoText);`
- Idempotent re-run: WC `createOrFind` by slug returns the existing term; the
  update call passes the new composite description, overwriting the previous
  marker block.

**config/migration.php**
- Add `category_seo` group:
  ```php
  'category_seo' => [
      'custom_field_key' => env('SHOPWARE_CATEGORY_SEO_CUSTOM_FIELD', 'custom_seo_text_below'),
      'enabled' => env('SHOPWARE_CATEGORY_SEO_ENABLED', true),
  ],
  ```

## WordPress side — how it gets displayed

Once the term description holds the SEO copy, display depends on the active
theme:

- **Most modern themes** (Storefront, Astra, Kadence, GeneratePress) render
  the term description automatically via the
  `woocommerce_taxonomy_archive_description` hook — appears **above** the
  product grid by default.
- **To render it below** the grid (the Shopware-native placement), the shop
  needs a small snippet, e.g.:
  ```php
  add_action('woocommerce_after_shop_loop', function () {
      if (is_product_category()) {
          $term = get_queried_object();
          if ($term && trim($term->description) !== '') {
              echo '<div class="category-seo-text">'.wp_kses_post($term->description).'</div>';
          }
      }
  }, 20);
  ```
  Document this snippet in the README's post-migration section.

## Testing

**Unit — `CategorySeoTextResolver`:**
- Returns the configured custom-field value when present.
- Falls back to CMS slot text when no custom field.
- Returns `''` when neither source has data.
- Picks the *last* text slot when multiple exist.

**Unit — `CategoryTransformer`:**
- Existing tests stay green (description stays untouched when seoText is `''`).
- New: when seoText is non-empty, output description ends with the marker
  comment + text.
- New: a second call with the same seoText is byte-identical (idempotent).

**Feature — `MigrateCategoriesJob`:**
- End-to-end with stubbed reader: category with cms_page_id + slot fixture
  produces the expected combined description in the WC POST payload.

## Out of scope (deferred)

- Translation migration of the SEO text (matches operator decision).
- Yoast/RankMath active detection — keep the unconditional meta emit.
- Multiple SEO text slots concatenated.
- Operator UI for choosing the custom-field key (env var only for v1).
