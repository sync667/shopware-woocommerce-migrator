import { test, expect } from '@playwright/test';

test.describe('Migration Log Page', () => {
    test('renders the log page with heading', async ({ page }) => {
        // Navigate to dashboard, then to migration, then to logs
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();
        await page.locator('a:has-text("Logs")').click();

        await expect(page.locator('h1')).toContainText('Migration Logs');
    });

    test('has back navigation to migration detail', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();
        await page.locator('a:has-text("Logs")').click();

        const backLink = page.locator('a[href^="/migrations/"]').first();
        await expect(backLink).toBeVisible();
    });

    test('displays Export CSV button', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();
        await page.locator('a:has-text("Logs")').click();

        await expect(page.locator('button:has-text("Export CSV")')).toBeVisible();
    });

    test('displays entity type filter dropdown', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();
        await page.locator('a:has-text("Logs")').click();

        const entityFilter = page.locator('select').first();
        await expect(entityFilter).toBeVisible();
        await expect(entityFilter.locator('option').first()).toHaveText('All Entities');
    });

    test('displays log level filter dropdown', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();
        await page.locator('a:has-text("Logs")').click();

        const levelFilter = page.locator('select').nth(1);
        await expect(levelFilter).toBeVisible();
        await expect(levelFilter.locator('option').first()).toHaveText('All Levels');
    });

    test('displays search input in log table', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();
        await page.locator('a:has-text("Logs")').click();

        const searchInput = page.locator('input[placeholder="Search logs..."]');
        await expect(searchInput).toBeVisible();
    });

    test('displays log table with correct headers', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();
        await page.locator('a:has-text("Logs")').click();

        await expect(page.locator('th:has-text("Level")')).toBeVisible();
        await expect(page.locator('th:has-text("Entity")')).toBeVisible();
        await expect(page.locator('th:has-text("Message")')).toBeVisible();
        await expect(page.locator('th:has-text("Time")')).toBeVisible();
    });

    test('entity filter has all entity type options', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();
        await page.locator('a:has-text("Logs")').click();

        const entityFilter = page.locator('select').first();
        const options = entityFilter.locator('option');

        // All Entities + 9 entity types
        await expect(options).toHaveCount(10);
    });

    test('level filter has all level options', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();
        await page.locator('a:has-text("Logs")').click();

        const levelFilter = page.locator('select').nth(1);
        const options = levelFilter.locator('option');

        // All Levels + debug, info, warning, error
        await expect(options).toHaveCount(5);
    });
});
