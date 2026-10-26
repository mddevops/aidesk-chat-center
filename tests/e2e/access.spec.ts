import { test, expect } from '@playwright/test';

test('owner manages real directories and grants an operator restricted access', async ({ page }, testInfo) => {
    const suffix = `${testInfo.project.name}-${Date.now()}`;
    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill('e2e-owner-a@example.test');
    await page.getByLabel('Пароль', {exact: true}).fill('E2eOnlyPassword123');
    await page.getByRole('button', {name: 'Войти', exact: true}).click();
    await expect(page.getByRole('heading', {level: 1})).toHaveText('Организация и команда');
    const csrfDenied = await page.request.post('/settings/showrooms', {data: {name: 'Must not be created'}});
    expect(csrfDenied.status()).toBe(419);
    const showroom = page.locator('section').filter({has: page.getByRole('heading', {name: 'Автосалоны', exact: true})});
    await showroom.getByLabel('Название', {exact: true}).fill(`Салон ${suffix}`);
    await showroom.getByRole('button', {name: 'Добавить', exact: true}).click();
    await expect(showroom.getByText(`Салон ${suffix}`, {exact: true})).toBeVisible();
    const department = page.locator('section').filter({has: page.getByRole('heading', {name: 'Отделы', exact: true})});
    await department.getByLabel('Название', {exact: true}).fill(`Продажи ${suffix}`);
    await department.getByRole('button', {name: 'Добавить', exact: true}).click();
    await expect(department.getByText(`Продажи ${suffix}`, {exact: true})).toBeVisible();
    await page.getByLabel('Название сайта', {exact: true}).fill(`Сайт ${suffix}`);
    await page.getByLabel('Адрес сайта', {exact: true}).fill(`https://${suffix}.example.test`);
    await page.getByRole('combobox', {name: 'Автосалон', exact: true}).selectOption({label: `Салон ${suffix}`});
    await page.getByRole('button', {name: 'Добавить сайт', exact: true}).click();
    await expect(page.getByText(`Сайт ${suffix}`, {exact: true})).toBeVisible();
    await page.getByLabel('Имя сотрудника', {exact: true}).fill(`Оператор ${suffix}`);
    await page.getByLabel('Почта сотрудника', {exact: true}).fill(`operator-${suffix}@example.test`);
    await page.getByLabel('Пароль сотрудника', {exact: true}).fill('OperatorPassword123');
    await page.getByLabel('Повторите пароль', {exact: true}).fill('OperatorPassword123');
    await page.getByRole('combobox', {name: 'Отдел сотрудника', exact: true}).selectOption({label: `Продажи ${suffix}`});
    await page.getByRole('button', {name: 'Добавить сотрудника', exact: true}).click();
    await expect(page.getByText(`Оператор ${suffix}`, {exact: true})).toBeVisible();
    await page.reload();
    await expect(page.getByText(`Сайт ${suffix}`, {exact: true})).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.getByRole('button', {name: 'Выйти', exact: true}).click();
    await page.getByLabel('Электронная почта').fill(`operator-${suffix}@example.test`);
    await page.getByLabel('Пароль', {exact: true}).fill('OperatorPassword123');
    await page.getByRole('button', {name: 'Войти', exact: true}).click();
    await expect(page.getByRole('heading', {level: 1})).toHaveText('Организация и команда');
    await expect(page.getByRole('button', {name: 'Добавить сайт', exact: true})).toHaveCount(0);
    await expect(page.getByRole('heading', {name: 'Сотрудники', exact: true})).toHaveCount(0);
});

test('private Reverb subscription requires the authenticated operator and tenant', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill('e2e-owner-a@example.test');
    await page.getByLabel('Пароль', {exact: true}).fill('E2eOnlyPassword123');
    await page.getByRole('button', {name: 'Войти', exact: true}).click();
    await expect(page.getByRole('heading', {level: 1})).toHaveText('Организация и команда');
    await page.reload();
    const result = await page.evaluate(async ({ key, port }) => {
        const props = JSON.parse(document.querySelector('#app')!.getAttribute('data-page')!).props;
        const channel = `private-organizations.${props.organization.id}.operators.${props.auth.user.id}`;
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content;
        return await new Promise<{event: string; foreignStatus: number}>((resolve, reject) => {
            const socket = new WebSocket(`ws://127.0.0.1:${port}/app/${key}?protocol=7&client=js&version=8.4.0`);
            const timer = setTimeout(() => { socket.close(); reject(new Error('Private subscription timed out')); }, 7000);
            let foreignStatus = 0;
            socket.onmessage = async message => {
                try {
                    const event = JSON.parse(message.data);
                    if (event.event === 'pusher:connection_established') {
                        const socketId = JSON.parse(event.data).socket_id;
                        const authorize = (name: string) => fetch('/broadcasting/auth', {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token}, body: JSON.stringify({socket_id: socketId, channel_name: name})});
                        const foreign = await authorize(`private-organizations.999999.operators.${props.auth.user.id}`);
                        foreignStatus = foreign.status;
                        const response = await authorize(channel);
                        if (!response.ok) throw new Error(`Authorization failed: ${response.status}`);
                        const auth = await response.json();
                        socket.send(JSON.stringify({event: 'pusher:subscribe', data: {channel, auth: auth.auth}}));
                    }
                    if (event.event === 'pusher_internal:subscription_succeeded') {clearTimeout(timer); socket.close(); resolve({event: event.event, foreignStatus});}
                } catch (error) { clearTimeout(timer); socket.close(); reject(error); }
            };
            socket.onerror = () => {clearTimeout(timer); reject(new Error('WebSocket failed'));};
        });
    }, {key: process.env.REVERB_APP_KEY || 'aidesk-local-public', port: process.env.REVERB_PORT || '8080'});
    expect(result.foreignStatus).toBe(403);
    expect(result.event).toBe('pusher_internal:subscription_succeeded');
});
