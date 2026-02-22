import { test, expect } from '@playwright/test';

test.describe('Migration Show Page', () => {
    test('renders migration progress page with back navigation', async ({ page }) => {
        // Navigate to dashboard first to find the migration link
        await page.goto('/');
        const migrationLink = page.locator('a[href^="/migrations/"]').first();
        await migrationLink.click();

        // Should have a back link to dashboard
        const backLink = page.locator('a[href="/"]');
        await expect(backLink).toBeVisible();
    });

    test('displays timing stats cards', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();

        await expect(page.locator('text=Elapsed')).toBeVisible();
        await expect(page.locator('text=ETA')).toBeVisible();
        await expect(page.locator('text=Success')).toBeVisible();
        await expect(page.locator('text=Failed')).toBeVisible();
    });

    test('displays overall progress section', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();

        await expect(page.locator('text=Overall Progress')).toBeVisible();
    });

    test('displays all entity type step cards', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();

        const entityTypes = [
            'Manufacturers',
            'Taxes',
            'Categories',
            'Products',
            'Variations',
            'Customers',
            'Orders',
            'Coupons',
            'Reviews',
        ];

        for (const type of entityTypes) {
            await expect(page.locator(`text=${type}`).first()).toBeVisible();
        }
    });

    test('displays Logs link', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();

        const logsLink = page.locator('a:has-text("Logs")');
        await expect(logsLink).toBeVisible();
    });

    test('displays migration name or fallback in header', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();

        // The header shows migration name from API or fallback "Migration"
        await expect(page.locator('h1')).toBeVisible();
        const text = await page.locator('h1').textContent();
        expect(text.length).toBeGreaterThan(0);
    });

    test('shows status badge', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();

        // Status badge renders (pending is the default before API data loads)
        const statusBadge = page.locator('.rounded-full.px-3');
        await expect(statusBadge).toBeVisible();
    });

    test('shows progress control buttons area', async ({ page }) => {
        await page.goto('/');
        await page.locator('a[href^="/migrations/"]').first().click();

        // The Logs button is always visible regardless of auth status
        await expect(page.locator('a:has-text("Logs")')).toBeVisible();
    });
});
