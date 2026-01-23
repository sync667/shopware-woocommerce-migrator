# E2E Testing with Playwright

This directory contains end-to-end tests for the frontend application using Playwright.

## Running Tests

### Prerequisites
- Ensure you have installed dependencies: `npm install`
- Install Playwright browsers: `npx playwright install chromium`

### Running Tests Locally

```bash
# Run all tests in headless mode
npm run test:e2e

# Run tests with UI mode (interactive)
npm run test:e2e:ui

# Run tests in debug mode
npm run test:e2e:debug
```

### Test Structure

Tests are organized by page/feature:
- `home.spec.ts` - Tests for the home page and navigation
- `login.spec.ts` - Tests for the login page

## Configuration

The Playwright configuration is in `playwright.config.ts`. Key settings:
- Tests run against `http://localhost:4173` (preview server)
- The app is automatically built and served before tests run
- Only Chromium browser is configured (can be extended for Firefox, Safari)
- Tests run in headless mode by default

## CI Integration

E2E tests run automatically in GitHub Actions on:
- Pushes to `master`, `main`, or `develop` branches
- Pull requests targeting those branches

Test reports are uploaded as artifacts and available for 30 days.

## Writing New Tests

Follow the existing test patterns:
1. Create a new `.spec.ts` file in the `e2e/` directory
2. Use `test.describe()` to group related tests
3. Use `test.beforeEach()` for common setup
4. Use Playwright's built-in assertions and locators
5. Keep tests focused and independent

Example:
```typescript
import { test, expect } from '@playwright/test';

test.describe('My Feature', () => {
  test('should do something', async ({ page }) => {
    await page.goto('/my-page');
    await expect(page.locator('h1')).toBeVisible();
  });
});
```
