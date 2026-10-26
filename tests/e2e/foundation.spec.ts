import { test, expect } from '@playwright/test';

test('Russian application loads, fits the viewport and persists theme', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Каждый разговор');
    await expect(page.locator('html')).toHaveAttribute('lang', 'ru');
    const toggle = page.getByRole('button', { name: /Включить .* тему/ });
    const wasDark = await page.locator('html').evaluate(el => el.classList.contains('dark'));
    await toggle.click();
    await expect(page.locator('html')).toHaveClass(wasDark ? '' : 'dark');
    await page.reload();
    await expect(page.locator('html')).toHaveClass(wasDark ? '' : 'dark');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
});
