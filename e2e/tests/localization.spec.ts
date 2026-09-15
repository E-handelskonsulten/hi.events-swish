import { test, expect } from '../fixtures';

test.describe('@smoke locale resolution', () => {
  test.describe('Swedish browser', () => {
    test.use({ locale: 'sv-SE' });

    test('a browser sending Accept-Language sv-SE gets the Swedish login page', async ({ page }) => {
      await page.goto('/auth/login');
      await expect(page.getByRole('button', { name: 'Logga in' })).toBeVisible();
    });
  });

  test.describe('English browser', () => {
    test.use({ locale: 'en-US' });

    test('a browser sending Accept-Language en-US gets the English login page', async ({ page }) => {
      await page.goto('/auth/login');
      await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
    });
  });
});
