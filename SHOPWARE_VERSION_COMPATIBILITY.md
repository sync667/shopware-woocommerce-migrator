# Shopware 6 Multi-Version Compatibility Plan

## Overview

This document describes the comprehensive plan for supporting **all Shopware 6.x versions** (6.5, 6.6, 6.7+) in the Shopware-to-WooCommerce migration tool. It covers version detection, database schema differences, and the code adaptations required.

## Supported Version Matrix

| Shopware Version Line | Release Range | Status | Notes |
|----------------------|---------------|--------|-------|
| **6.5.x** | 6.5.3.3 – 6.5.8.13 | ✅ Supported | Baseline schema |
| **6.6.x** | 6.6.0.0 – 6.6.10.13 | ✅ Supported | Stock migration, technical_name columns added |
| **6.7.x** | 6.7.0.0 – 6.7.7.1+ | ✅ Supported | product.type column, technical_name required |

## Version Detection Strategy

The tool auto-detects the Shopware major version by inspecting the database schema using `information_schema`:

```
product.type column exists       → 6.7+
payment_method.technical_name    → 6.6+
product table exists             → 6.5
```

Implementation: `App\Services\ShopwareVersionDetector`

The detected version is:
1. Returned to the frontend UI (displayed as a badge)
2. Stored in the migration settings JSON alongside DB credentials
3. Accessible via `ShopwareDB::shopwareVersion()` and `ShopwareDB::isAtLeast('6.6')`

## Database Schema Differences

### Tables & Columns Used by This Tool

All readers query the Shopware 6 database directly via MySQL. Below is a complete audit of tables/columns referenced by each Reader and their availability across versions.

### Product Tables

| Column / Feature | 6.5 | 6.6 | 6.7 | Impact on Tool |
|-----------------|-----|-----|-----|----------------|
| `product.stock` | ✅ | ✅ | ✅ | Safe - used in all versions |
| `product.available_stock` | ✅ | ⚠️ Migrated to stock | ❌ Removed | Tool uses `stock` only - **no impact** |
| `product.type` | ❌ | ❌ | ✅ Added | Tool derives type from `child_count` - **no impact** |
| `product.states` | ✅ | ✅ | ⚠️ Deprecated | Not referenced by tool - **no impact** |
| `product.canonical_product_version_id` | ❌ | ✅ Added | ✅ | Not referenced by tool - **no impact** |
| `product.is_closeout` | ✅ | ✅ | ✅ | Safe |
| `product.version_id` | ✅ | ✅ | ✅ | Core filtering mechanism |
| `product_translation.*` | ✅ | ✅ | ✅ | Stable across versions |
| `product_media.*` | ✅ | ✅ | ✅ | Stable |
| `product_category.*` | ✅ | ✅ | ✅ | Stable |
| `product_configurator_setting.*` | ✅ | ✅ | ✅ | Stable |
| `product_property.*` | ✅ | ✅ | ✅ | Stable |
| `product_tag.*` | ✅ | ✅ | ✅ | Stable |
| `product_cross_selling.*` | ✅ | ✅ | ✅ | Stable |
| `product_option.*` | ✅ | ✅ | ✅ | Stable |
| `product_visibility.*` | ✅ | ✅ | ✅ | Stable |
| `product_stream_mapping.*` | ✅ | ✅ | ✅ | Stable (added in 6.4) |

### Order Tables

| Column / Feature | 6.5 | 6.6 | 6.7 | Impact on Tool |
|-----------------|-----|-----|-----|----------------|
| `order.*` (core columns) | ✅ | ✅ | ✅ | Stable |
| `order.version_id` | ✅ | ✅ | ✅ | Core filtering |
| `order_line_item.payload` | ✅ JSON | ✅ JSON | ✅ JSON | Stable format |
| `order_customer.*` | ✅ | ✅ | ✅ | Stable |
| `order_address.first_name` | ✅ VARCHAR(50) | ✅ VARCHAR(50) | ✅ VARCHAR(255) | Length increased in 6.7, no read impact |
| `order_delivery.*` | ✅ | ✅ | ✅ | Stable |
| `state_machine_state.technical_name` | ✅ | ✅ | ✅ | Stable |

### Customer Tables

| Column / Feature | 6.5 | 6.6 | 6.7 | Impact on Tool |
|-----------------|-----|-----|-----|----------------|
| `customer.*` (core columns) | ✅ | ✅ | ✅ | Stable |
| `customer.first_name` | ✅ VARCHAR(50) | ✅ VARCHAR(50) | ✅ VARCHAR(255) | Length increased, no read impact |
| `customer_address.*` | ✅ | ✅ | ✅ | Stable |

### Payment & Shipping Methods

| Column / Feature | 6.5 | 6.6 | 6.7 | Impact on Tool |
|-----------------|-----|-----|-----|----------------|
| `payment_method.handler_identifier` | ✅ | ✅ | ✅ | Primary identifier used by tool |
| `payment_method.technical_name` | ❌ | ✅ Nullable | ✅ NOT NULL | Available in 6.6+, not currently referenced |
| `shipping_method.technical_name` | ❌ | ✅ Nullable | ✅ NOT NULL | Available in 6.6+, not currently referenced |
| `shipping_method_price.*` | ✅ | ✅ | ✅ | Stable |

### CMS Tables

| Column / Feature | 6.5 | 6.6 | 6.7 | Impact on Tool |
|-----------------|-----|-----|-----|----------------|
| `cms_page.*` | ✅ | ✅ | ✅ | Stable |
| `cms_section.*` | ✅ | ✅ | ✅ | Stable |
| `cms_block.*` | ✅ | ✅ | ✅ | Stable |
| `cms_slot.*` | ✅ | ✅ | ✅ | Stable |
| `cms_slot_translation.config` | ✅ JSON | ✅ JSON | ✅ JSON | Stable |

### Other Tables

| Table | 6.5 | 6.6 | 6.7 | Impact on Tool |
|-------|-----|-----|-----|----------------|
| `tax` / `tax_rule` / `tax_rule_type` | ✅ | ✅ | ✅ | Stable |
| `promotion` / `promotion_discount` / `promotion_individual_code` | ✅ | ✅ | ✅ | Stable |
| `product_review` | ✅ | ✅ | ✅ | Stable |
| `product_manufacturer` / `product_manufacturer_translation` | ✅ | ✅ | ✅ | Stable |
| `seo_url` | ✅ | ✅ | ✅ | Stable |
| `custom_field` / `custom_field_set` | ✅ | ✅ | ✅ | Stable |
| `language` / `locale` | ✅ | ✅ | ✅ | Stable |
| `version` | ✅ | ✅ | ✅ | Stable |
| `media` / `media_translation` | ✅ | ✅ | ✅ | Stable |
| `country` / `country_state` | ✅ | ✅ | ✅ | Stable |

## Implementation Architecture

### Version Detection Flow

```
User enters DB credentials
    → Frontend calls POST /api/shopware/live-version
    → Backend creates ShopwareDB connection
    → ShopwareVersionDetector checks information_schema
    → Returns { version: "6.7", features: {...}, warnings: [...] }
    → Frontend displays version badge
    → Version stored in migration settings
    → All Jobs/Readers access version via ShopwareDB::shopwareVersion()
```

### Key Components

| Component | Purpose |
|-----------|---------|
| `ShopwareVersionDetector` | Detects version via schema inspection |
| `ShopwareDB::shopwareVersion()` | Returns cached version string |
| `ShopwareDB::isAtLeast('6.6')` | Version comparison helper |
| `ShopwareConfigController::detectVersion` | API endpoint for version detection |
| `Settings.jsx` → `detectedShopwareVersion` | Frontend display state |

## Current Compatibility Status

### ✅ Already Compatible (No Changes Needed)

The tool's SQL queries are **already compatible with all Shopware 6.5–6.7** versions because:

1. **Product queries** use `stock` (not `available_stock`) and derive type from `child_count` (not `product.type` or `product.states`)
2. **Payment method queries** use `handler_identifier` (present in all versions) not `technical_name`
3. **Shipping method queries** don't reference `technical_name`
4. **Order queries** use standard columns stable across all 6.x versions
5. **CMS queries** use the stable cms_page/section/block/slot hierarchy
6. **No column-specific conditionals are currently needed** since the tool avoids version-specific columns

### 🔮 Future Enhancements (Optional)

These are opportunities to leverage newer Shopware features but are not required for compatibility:

| Enhancement | Shopware Version | Benefit |
|------------|-----------------|---------|
| Use `product.type` for digital product detection | 6.7+ | Better product type mapping (digital vs physical) |
| Use `payment_method.technical_name` | 6.6+ | More reliable payment method identification |
| Use `shipping_method.technical_name` | 6.6+ | More reliable shipping method identification |
| Read `product.states` for is-download detection | 6.5–6.6 | Digital product support for older versions |

## Testing Strategy

### Unit Tests
- `ShopwareVersionDetector` tests with mocked database responses
- Tests for `ShopwareDB::isAtLeast()` version comparison

### Integration Testing Approach
For thorough multi-version testing, maintain test databases (or SQL dumps) for each major version:
- Shopware 6.5.8.x database dump
- Shopware 6.6.10.x database dump
- Shopware 6.7.7.x database dump

Run the full migration against each to verify compatibility.

## Risks and Mitigations

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Future Shopware 6.8+ breaks schema | Medium | Version detector will return 'unknown', tool falls back gracefully |
| Early 6.5 minor versions have missing tables | Low | Tool requires 6.5.3+ minimum; core tables stable since 6.4 |
| Custom plugins alter schema | Low | Tool reads only core Shopware tables |
| Column type changes (VARCHAR length) | Very Low | Read operations unaffected by length increases |

## Summary

The migration tool is **already compatible** with Shopware 6.5, 6.6, and 6.7 because it uses only stable, core database columns that have remained consistent across all 6.x releases. The version detection system added in this PR provides:

1. **Visibility** – Users see which Shopware version they're migrating from
2. **Future-proofing** – The `isAtLeast()` helper enables version-conditional logic when needed
3. **Diagnostics** – The feature detection report helps troubleshoot connection issues
4. **Extensibility** – New version-specific features can be gated behind version checks
