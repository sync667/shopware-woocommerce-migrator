import { test, expect } from '@playwright/test';

test.describe('Dashboard Page', () => {
    test('renders the main heading and navigation', async ({ page }) => {
        await page.goto('/');

        await expect(page.locator('h1')).toContainText('Shopware');
        await expect(page.locator('h1')).toContainText('WooCommerce');
        await expect(page.locator('h1')).toContainText('Migration');
    });

    test('shows the New Migration button linking to settings', async ({ page }) => {
        await page.goto('/');

        const newMigrationLink = page.locator('a[href="/settings"]');
        await expect(newMigrationLink).toBeVisible();
        await expect(newMigrationLink).toContainText('New Migration');
    });

    test('displays all 8 entity type step cards', async ({ page }) => {
        await page.goto('/');

        const entityTypes = [
            'Manufacturers',
            'Taxes',
            'Categories',
            'Products',
            'Customers',
            'Orders',
            'Coupons',
            'Reviews',
        ];

        for (const type of entityTypes) {
            await expect(page.locator('text=' + type).first()).toBeVisible();
        }
    });

    test('displays Migration Runs section header', async ({ page }) => {
        await page.goto('/');

        await expect(page.locator('h2:has-text("Migration Runs")')).toBeVisible();
    });

    test('shows seeded migration run with status badge', async ({ page }) => {
        await page.goto('/');

        // The global setup created 'E2E Test Migration'
        await expect(page.locator('text=E2E Test Migration')).toBeVisible();
        await expect(page.locator('text=running').first()).toBeVisible();
    });

    test('migration run links to detail page', async ({ page }) => {
        await page.goto('/');

        const migrationLink = page.locator('a[href^="/migrations/"]').first();
        await expect(migrationLink).toBeVisible();
        await expect(migrationLink).toContainText('E2E Test Migration');
    });
});
