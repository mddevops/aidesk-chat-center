import { defineConfig, devices } from '@playwright/test';
export default defineConfig({
    testDir: './tests/e2e',
    webServer: { command: 'node tests/e2e/fixtures/sites.mjs', url: 'http://127.0.0.1:8091', reuseExistingServer: false },
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? 'github' : 'list',
    use: { baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8000', trace: 'retain-on-failure' },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'mobile', use: { ...devices['iPhone 13'], defaultBrowserType: 'chromium' } },
    ],
});
