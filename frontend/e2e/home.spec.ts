import { test, expect } from '@playwright/test';

test.describe('Home Page', () => {
  test('should load and display the home page', async ({ page }) => {
    await page.goto('/');
    
    // Check that the page loads
    await expect(page).toHaveTitle('frontend');
    
    // Check that the main heading is visible
    await expect(page.locator('h1:has-text("Welcome to the App")')).toBeVisible();
  });

  test('should display guest content when not authenticated', async ({ page }) => {
    await page.goto('/');
    
    // Check for guest content
    await expect(page.locator('text=Please login to access the dashboard.')).toBeVisible();
    
    // Check that login and register buttons are visible
    await expect(page.locator('a:has-text("Login")')).toBeVisible();
    await expect(page.locator('a:has-text("Register")')).toBeVisible();
  });

  test('should navigate to login page', async ({ page }) => {
    await page.goto('/');
    
    // Click the login link
    await page.locator('a:has-text("Login")').click();
    
    // Verify we're on the login page
    await expect(page).toHaveURL('/login');
    await expect(page.locator('h1:has-text("Login")')).toBeVisible();
  });

  test('should navigate to register page', async ({ page }) => {
    await page.goto('/');
    
    // Click the register link
    await page.locator('a:has-text("Register")').click();
    
    // Verify we're on the register page
    await expect(page).toHaveURL('/register');
    await expect(page.locator('h1:has-text("Register")')).toBeVisible();
  });
});
