import { test, expect } from '@playwright/test';

test.describe('Navigation Flow', () => {
    test('can navigate from dashboard to settings and back', async ({ page }) => {
        await page.goto('/');

        // Go to settings
        await page.locator('a[href="/settings"]').click();
        await expect(page).toHaveURL('/settings');
        await expect(page.locator('h1')).toContainText('New Migration');

        // Go back to dashboard
        await page.locator('a[href="/"]').click();
        await expect(page).toHaveURL('/');
        await expect(page.locator('h1')).toContainText('Shopware');
    });

    test('settings page form is interactive', async ({ page }) => {
        await page.goto('/settings');

        // Fill migration name
        const nameInput = page.locator('input[placeholder*="Full Migration"]');
        await nameInput.fill('E2E Test Migration');
        await expect(nameInput).toHaveValue('E2E Test Migration');

        // Fill Shopware host
        await page.locator('input[placeholder="127.0.0.1"]').fill('shopware-db.local');

        // Fill WooCommerce URL
        await page.locator('input[placeholder*="https://woo"]').fill('https://woo.example.com');

        // Fill Consumer Key
        await page.locator('input[placeholder="ck_..."]').fill('ck_test123');

        // Verify values persist in form
        await expect(page.locator('input[placeholder="127.0.0.1"]')).toHaveValue(
            'shopware-db.local',
        );
        await expect(page.locator('input[placeholder*="https://woo"]')).toHaveValue(
            'https://woo.example.com',
        );
    });

    test('dashboard page is accessible and has correct structure', async ({ page }) => {
        await page.goto('/');

        // Page title area
        await expect(page.locator('h1')).toBeVisible();

        // Step cards grid
        const stepCards = page.locator('.rounded-lg.border.p-4');
        const count = await stepCards.count();
        expect(count).toBeGreaterThanOrEqual(8);

        // Migration runs section
        await expect(page.locator('h2:has-text("Migration Runs")')).toBeVisible();
    });

    test('page has correct meta tags', async ({ page }) => {
        await page.goto('/');

        const charset = await page.locator('meta[charset]').getAttribute('charset');
        expect(charset).toBe('utf-8');

        const viewport = await page.locator('meta[name="viewport"]').getAttribute('content');
        expect(viewport).toContain('width=device-width');
    });
});
