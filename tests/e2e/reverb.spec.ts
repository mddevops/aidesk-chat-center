import { test, expect } from '@playwright/test';

test('Reverb accepts a protocol connection from the configured application origin', async ({ page }) => {
    await page.goto('/');
    const result = await page.evaluate(async ({ key, port }) => {
        return await new Promise<string>((resolve, reject) => {
            const socket = new WebSocket(`ws://127.0.0.1:${port}/app/${key}?protocol=7&client=js&version=8.4.0`);
            const timer = setTimeout(() => { socket.close(); reject(new Error('Reverb connection timed out')); }, 7000);
            socket.onmessage = event => { clearTimeout(timer); resolve(JSON.parse(event.data).event); socket.close(); };
            socket.onerror = () => { clearTimeout(timer); reject(new Error('Reverb connection failed')); };
        });
    }, { key: process.env.REVERB_APP_KEY || 'aidesk-local-public', port: process.env.REVERB_PORT || '8080' });
    expect(result).toBe('pusher:connection_established');
});
