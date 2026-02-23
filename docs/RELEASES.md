# Release Pipeline Documentation

This document explains the automated release pipeline for the Shopware to WooCommerce Migrator.

## Overview

The project uses **automated semantic versioning** with:
- [Release Please](https://github.com/googleapis/release-please) - Automated releases
- [Conventional Commits](https://www.conventionalcommits.org/) - Standardized commit messages
- [Semantic Versioning](https://semver.org/) - Version numbering
- GitHub Actions - CI/CD automation

## How It Works

### 1. Developer Commits Code

Developers commit using **Conventional Commit** format:

```bash
git commit -m "feat: add automatic language detection"
git commit -m "fix: resolve SQL query error in ProductReader"
git commit -m "docs: update setup guide"
```

### 2. Push to Master Branch

```bash
git push origin master
```

### 3. Release Please Creates PR

GitHub Actions automatically:
- Analyzes all commits since last release
- Determines version bump (major/minor/patch)
- Generates changelog entries
- Creates a **Release PR** with:
  - Updated `CHANGELOG.md`
  - Updated `composer.json` version
  - New git tag

**Example Release PR:**
```
chore(main): release 1.2.0

Changes:
- feat: add automatic language detection
- fix: resolve SQL query error
- docs: update setup guide

composer.json version: 0.1.0 → 1.2.0
```

### 4. Maintainer Merges Release PR

After reviewing the Release PR, a maintainer merges it to `master`.

### 5. Automated Release

Once merged, GitHub Actions:
- Creates a GitHub Release with release notes
- Publishes release assets (source code archive)
- Tags the commit with version number

## Commit Message Format

### Structure

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

### Types and Version Bumps

| Type | Version Bump | Example | New Version |
|------|--------------|---------|-------------|
| `feat` | **Minor** (0.X.0) | `feat: add CMS migration` | 1.2.0 → 1.3.0 |
| `fix` | **Patch** (0.0.X) | `fix: handle null values` | 1.2.0 → 1.2.1 |
| `feat!` or `BREAKING CHANGE:` | **Major** (X.0.0) | `feat!: redesign API` | 1.2.0 → 2.0.0 |
| `perf` | **Patch** | `perf: optimize queries` | 1.2.0 → 1.2.1 |
| `refactor` | **Patch** | `refactor: extract service` | 1.2.0 → 1.2.1 |
| `docs` | **Patch** | `docs: add SSH guide` | 1.2.0 → 1.2.1 |
| `style` | **None** | `style: format code` | No release |
| `test` | **None** | `test: add unit tests` | No release |
| `chore` | **None** | `chore: update deps` | No release |
| `ci` | **None** | `ci: add build step` | No release |

### Breaking Changes

Use `!` or `BREAKING CHANGE:` footer for major version bumps:

```bash
# Method 1: Exclamation mark
git commit -m "feat!: remove PHP 8.2 support

Minimum PHP version is now 8.3"

# Method 2: Footer
git commit -m "feat: redesign configuration API

BREAKING CHANGE: Configuration format has changed.
See migration guide in docs/migration-v2.md"
```

## Examples

### Adding a Feature

```bash
git add app/Services/NewService.php
git commit -m "feat: add automatic backup before migration

Added BackupService that creates WooCommerce data snapshots
before each migration run. Backups are stored in storage/backups/."
git push origin master
```

**Result:** Version bump 1.2.0 → **1.3.0**

### Fixing a Bug

```bash
git add app/Shopware/Readers/ProductReader.php
git commit -m "fix: use product_media_id instead of cover_id

The cover_id column doesn't exist in Shopware database schema.
Changed query to use product_media_id as per actual schema."
git push origin master
```

**Result:** Version bump 1.2.0 → **1.2.1**

### Breaking Change

```bash
git add app/Services/MigrationService.php app/Http/Controllers/MigrationController.php
git commit -m "feat!: redesign migration configuration API

BREAKING CHANGE: The migration configuration format has changed.
Old format: { source: {}, target: {} }
New format: { settings: { shopware: {}, woocommerce: {} } }

See migration guide: docs/migration-v2.md"
git push origin master
```

**Result:** Version bump 1.2.0 → **2.0.0**

### Multiple Commits in One PR

```bash
git commit -m "feat: add SSH tunnel support"
git commit -m "docs: add SSH setup guide"
git commit -m "test: add SSH connection tests"
git push origin master
```

**Result:** Version bump 1.2.0 → **1.3.0** (highest bump wins)

## Changelog Generation

The `CHANGELOG.md` is automatically generated and categorized:

```markdown
## [1.3.0] - 2026-02-23

### Features
- add SSH tunnel support
- add automatic language detection

### Bug Fixes
- resolve SQL query error in ProductReader
- handle null category parent in transformer

### Documentation
- add SSH setup guide
- update Docker quick-start section

### Performance Improvements
- optimize product query with eager loading
```

## Manual Release (Emergency)

If automated releases fail, create manually:

```bash
# Update version in composer.json
vim composer.json  # Change "version": "1.2.0" to "1.3.0"

# Update CHANGELOG.md
vim CHANGELOG.md  # Add release notes

# Commit changes
git add composer.json CHANGELOG.md
git commit -m "chore: release v1.3.0"
git push origin master

# Create and push tag
git tag -a v1.3.0 -m "Release v1.3.0"
git push origin v1.3.0

# Create GitHub Release manually at:
# https://github.com/sync667/shopware-woocommerce-migrator/releases/new
```

## Version Numbering Strategy

Given version `MAJOR.MINOR.PATCH` (e.g., `2.4.7`):

- **MAJOR (2)**: Breaking changes - incompatible API changes
  - Example: Removing PHP 8.2 support, redesigning migration config format
- **MINOR (4)**: New features - backward-compatible functionality
  - Example: Adding CMS migration, adding SSH tunnel support
- **PATCH (7)**: Bug fixes - backward-compatible fixes
  - Example: Fixing SQL errors, handling null values, documentation updates

## Pre-release Versions

For beta/alpha releases, use pre-release tags:

```bash
git tag -a v2.0.0-beta.1 -m "Beta release 2.0.0-beta.1"
git push origin v2.0.0-beta.1
```

## Best Practices

### ✅ Do

- Write clear, descriptive commit messages
- Use conventional commit format for all commits
- Keep commits focused on single changes
- Update tests and documentation in the same commit
- Review the generated Release PR before merging

### ❌ Don't

- Don't use generic messages like "fix stuff" or "update code"
- Don't mix multiple features in one commit
- Don't commit directly to master without conventional format
- Don't manually edit CHANGELOG.md (it's auto-generated)
- Don't create tags manually (let release-please handle it)

## Troubleshooting

### Release PR Not Created

**Problem:** Pushed commits but no Release PR appeared

**Solutions:**
- Check that commits use conventional format
- Verify commits are on `master` branch
- Check GitHub Actions logs: https://github.com/sync667/shopware-woocommerce-migrator/actions
- Ensure previous Release PR is merged

### Wrong Version Bump

**Problem:** Expected minor bump but got patch

**Solutions:**
- Check commit type: `feat` = minor, `fix` = patch
- Verify commit message format: `feat: description` (not `feature:` or `added:`)
- Multiple commits? Highest bump wins (feat > fix)

### CHANGELOG Missing Entry

**Problem:** Commit not appearing in changelog

**Solutions:**
- Check commit type - `chore`, `test`, `ci` don't appear in changelog
- Verify commit follows conventional format exactly
- Check `release-please-config.json` for `hidden: true` types

## Configuration Files

- `.github/workflows/release.yml` - GitHub Actions workflow
- `release-please-config.json` - Release Please configuration
- `.release-please-manifest.json` - Current version tracking
- `CHANGELOG.md` - Auto-generated changelog
- `composer.json` - Version field updated automatically

## References

- [Conventional Commits Spec](https://www.conventionalcommits.org/)
- [Semantic Versioning](https://semver.org/)
- [Release Please Documentation](https://github.com/googleapis/release-please)
- [Contributing Guidelines](../CONTRIBUTING.md)
