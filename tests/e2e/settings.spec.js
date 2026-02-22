import { test, expect } from '@playwright/test';

test.describe('Settings Page', () => {
    test('renders the New Migration heading', async ({ page }) => {
        await page.goto('/settings');

        await expect(page.locator('h1')).toContainText('New Migration');
    });

    test('has back arrow linking to dashboard', async ({ page }) => {
        await page.goto('/settings');

        const backLink = page.locator('a[href="/"]');
        await expect(backLink).toBeVisible();
    });

    test('displays Migration Name input field', async ({ page }) => {
        await page.goto('/settings');

        const nameInput = page.locator('input[placeholder*="Full Migration"]');
        await expect(nameInput).toBeVisible();
        await expect(nameInput).toHaveValue('');
    });

    test('displays Shopware Source Database section with all fields', async ({ page }) => {
        await page.goto('/settings');

        await expect(page.locator('h2:has-text("Shopware Source Database")')).toBeVisible();

        // Verify key input fields exist
        await expect(page.locator('input[placeholder="127.0.0.1"]')).toBeVisible();
        await expect(page.locator('label:has-text("Database")')).toBeVisible();
        await expect(page.locator('label').filter({ hasText: /^Username$/ })).toBeVisible();
        await expect(page.locator('label:has-text("Password")').first()).toBeVisible();
        await expect(page.locator('input[placeholder*="https://shop"]')).toBeVisible();
    });

    test('displays WooCommerce Target API section with all fields', async ({ page }) => {
        await page.goto('/settings');

        await expect(page.locator('h2:has-text("WooCommerce Target API")')).toBeVisible();

        await expect(page.locator('input[placeholder*="https://woo"]')).toBeVisible();
        await expect(page.locator('input[placeholder="ck_..."]')).toBeVisible();
        await expect(page.locator('input[placeholder="cs_..."]')).toBeVisible();
    });

    test('displays WordPress Media API section', async ({ page }) => {
        await page.goto('/settings');

        await expect(page.locator('h2:has-text("WordPress Media API")')).toBeVisible();

        await expect(page.locator('label:has-text("WP Username")')).toBeVisible();
        await expect(page.locator('label:has-text("Application Password")')).toBeVisible();
    });

    test('has Dry Run and Start Migration buttons', async ({ page }) => {
        await page.goto('/settings');

        await expect(page.locator('button:has-text("Dry Run")')).toBeVisible();
        await expect(page.locator('button:has-text("Start Migration")')).toBeVisible();
    });

    test('has Test Connection buttons for Shopware and WooCommerce', async ({ page }) => {
        await page.goto('/settings');

        const testButtons = page.locator('button:has-text("Test Connection")');
        await expect(testButtons).toHaveCount(2);
    });

    test('can fill in the migration name', async ({ page }) => {
        await page.goto('/settings');

        const nameInput = page.locator('input[placeholder*="Full Migration"]');
        await nameInput.fill('Test Migration E2E');
        await expect(nameInput).toHaveValue('Test Migration E2E');
    });

    test('can fill in Shopware connection details', async ({ page }) => {
        await page.goto('/settings');

        await page.locator('input[placeholder="127.0.0.1"]').fill('db.example.com');

        const hostInput = page.locator('input[placeholder="127.0.0.1"]');
        await expect(hostInput).toHaveValue('db.example.com');
    });

    test('connection status indicators start in neutral state', async ({ page }) => {
        await page.goto('/settings');

        // Connection status dots should be gray (neutral) initially
        const statusDots = page.locator('.bg-gray-400');
        await expect(statusDots).toHaveCount(2);
    });

    test('navigating to dashboard via back arrow works', async ({ page }) => {
        await page.goto('/settings');

        await page.locator('a[href="/"]').click();

        await expect(page).toHaveURL('/');
        await expect(page.locator('h1')).toContainText('Shopware');
    });
});
