import { test, expect, type Page } from '@playwright/test';

type NoticeWindow = Window & { chatNotices: Array<{event_id: string; data: {message_id: number}}>; chatSocket: WebSocket };
async function operatorHeaders(page: Page) {
    const cookie = (await page.context().cookies()).find(cookie => cookie.name === 'XSRF-TOKEN');
    return {'X-XSRF-TOKEN': decodeURIComponent(cookie!.value), 'Accept': 'application/json'};
}
async function browserCall(page: Page, path: string, token?: string, data?: unknown) {
    return page.evaluate(async ({path, token, data}) => {
        const response = await fetch(`http://127.0.0.1:8000${path}`, {method: data === undefined ? 'GET' : 'POST', credentials: 'omit', headers: {'Content-Type': 'application/json', ...(token ? {Authorization: `Bearer ${token}`} : {})}, ...(data === undefined ? {} : {body: JSON.stringify(data)})});
        return {status: response.status, body: await response.json()};
    }, {path, token, data});
}

test('durable visitor/operator exchange across real website origins with private realtime notifications', async ({page, browser}, info) => {
    test.setTimeout(60000);
    const ports = info.project.name === 'mobile' ? [8093, 8094] : [8091, 8092];
    const suffix = `${info.project.name}-${Date.now()}`;
    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill('e2e-owner-a@example.test');
    await page.getByLabel('Пароль', {exact:true}).fill('E2eOnlyPassword123');
    await page.getByRole('button', {name:'Войти', exact:true}).click();
    await expect(page.getByRole('heading', {level:1})).toHaveText('Организация и команда');
    const headers = await operatorHeaders(page);
    const salon = await page.request.post('/settings/showrooms', {headers, data: {name:`Chat ${suffix}`}, maxRedirects:0});
    expect(salon.status()).toBe(302);
    const readProps = async () => { await page.reload(); return JSON.parse((await page.locator('#app').getAttribute('data-page'))!).props; };
    let props = await readProps();
    const showroom = props.showrooms.find((s: {name: string}) => s.name === `Chat ${suffix}`);
    const channels: string[] = [];
    for (const port of ports) {
        props = await readProps();
        let ownSite = props.sites.find((s: {origin: string}) => s.origin === `http://127.0.0.1:${port}`);
        if (!ownSite) {
            const site = await page.request.post('/settings/sites', {headers, data:{name:`Site ${suffix}-${port}`, origin:`http://127.0.0.1:${port}`, showroom_id:showroom.id}, maxRedirects:0});
            expect(site.status()).toBe(302);
            props = await readProps();
            ownSite = props.sites.find((s: {origin: string}) => s.origin === `http://127.0.0.1:${port}`);
        }
        const existing = (await (await page.request.get('/api/v1/channels')).json()).data.find((c: {site_id:number}) => c.site_id === ownSite.id);
        if (existing) channels.push(existing.public_id);
        else {
            const channel = await page.request.post('/api/v1/channels', {headers, data:{name:`Website ${port}`, site_id:ownSite.id}});
            expect(channel.status()).toBe(201);
            channels.push((await channel.json()).data.public_id);
        }
    }
    await page.reload();
    await page.evaluate(async ({key, port}) => {
        const target = window as unknown as NoticeWindow;
        target.chatNotices = [];
        const props = JSON.parse(document.querySelector('#app')!.getAttribute('data-page')!).props;
        const channel = `private-organizations.${props.organization.id}.operators.${props.auth.user.id}`;
        const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')!.content;
        await new Promise<void>((resolve, reject) => {
            const socket = new WebSocket(`ws://127.0.0.1:${port}/app/${key}?protocol=7&client=js&version=8.4.0`);
            target.chatSocket = socket;
            const timeout = setTimeout(() => reject(new Error('Subscription timed out')), 7000);
            socket.onmessage = async message => {
                const event = JSON.parse(message.data);
                if (event.event === 'pusher:connection_established') {
                    const response = await fetch('/broadcasting/auth', {method:'POST', headers:{'Content-Type':'application/json', 'X-CSRF-TOKEN':token}, body:JSON.stringify({socket_id:JSON.parse(event.data).socket_id, channel_name:channel})});
                    const result = await response.json();
                    socket.send(JSON.stringify({event:'pusher:subscribe', data:{channel, auth:result.auth}}));
                }
                if (event.event === 'pusher_internal:subscription_succeeded') {clearTimeout(timeout); resolve();}
                if (event.event === 'chat.message_available.v1') target.chatNotices.push(JSON.parse(event.data));
            };
            socket.onerror = () => {clearTimeout(timeout); reject(new Error('WebSocket failed'));};
        });
    }, {key:process.env.REVERB_APP_KEY || 'aidesk-local-public', port:process.env.REVERB_PORT || '8080'});
    const visitorContext = await browser.newContext();
    try {
        const visitor = await visitorContext.newPage();
        await visitor.goto(`http://127.0.0.1:${ports[0]}`);
        const base = `/widget/v1/channels/${channels[0]}`;
        const start = await browserCall(visitor, `${base}/sessions`, undefined, {});
        expect(start.status).toBe(201);
        const token = start.body.data.credential;
        expect((await browserCall(visitor, `${base}/messages`, token, {body:'Без контактов', client_message_id:crypto.randomUUID()})).status).toBe(403);
        expect((await browserCall(visitor, `${base}/identify`, token, {name:'Тестовый посетитель', phone:'8 (999) 123-45-67', consent:true, consent_version:'contact-v1'})).status).toBe(200);
        const message = {body:`Нужен автомобиль ${suffix}`, client_message_id:crypto.randomUUID()};
        const sent = await browserCall(visitor, `${base}/messages`, token, message);
        expect(sent.status).toBe(201);
        const retried = await browserCall(visitor, `${base}/messages`, token, message);
        expect(retried.status).toBe(200);
        expect(retried.body.data.id).toBe(sent.body.data.id);
        expect((await browserCall(visitor, `${base}/messages`, token, {...message, body:'Другой payload'})).status).toBe(409);
        await expect.poll(async () => page.evaluate(id => (window as unknown as NoticeWindow).chatNotices.some(event => event.data.message_id === id), sent.body.data.id), {timeout:15000}).toBeTruthy();
        const list = await (await page.request.get('/api/v1/conversations?q=9991234567')).json();
        let conversationId: number | undefined;
        for (const conversation of list.data) {
            const history = await (await page.request.get(`/api/v1/conversations/${conversation.id}/messages`)).json();
            if (history.data.some((entry: {id:number}) => entry.id === sent.body.data.id)) conversationId = conversation.id;
        }
        expect(conversationId).toBeDefined();
        const note = await page.request.post(`/api/v1/conversations/${conversationId}/messages`, {headers, data:{body:'Внутренняя заметка', kind:'note', client_message_id:crypto.randomUUID()}});
        expect(note.status()).toBe(201);
        const upload = await page.request.post('/api/v1/attachments', {headers, multipart:{file:{name:'offer.pdf', mimeType:'application/pdf', buffer:Buffer.from('%PDF-1.7\nAIDesk test offer\n%%EOF')}}});
        expect(upload.status()).toBe(201);
        const attachmentId = (await upload.json()).data.id;
        const reply = await page.request.post(`/api/v1/conversations/${conversationId}/messages`, {headers, data:{body:'Автомобиль в наличии', kind:'message', attachment_ids:[attachmentId], client_message_id:crypto.randomUUID()}});
        expect(reply.status()).toBe(201);
        const recovered = await browserCall(visitor, `${base}/messages?after_sequence=1`, token);
        expect(recovered.body.data).toHaveLength(1);
        expect(recovered.body.data[0].body).toBe('Автомобиль в наличии');
        const downloaded = await visitor.evaluate(async ({path, token}) => {
            const response = await fetch(`http://127.0.0.1:8000${path}`, {headers:{Authorization:`Bearer ${token}`}, credentials:'omit'});
            return {status:response.status, mime:response.headers.get('Content-Type'), text:await response.text()};
        }, {path:`${base}/attachments/${attachmentId}`, token});
        expect(downloaded.status).toBe(200);
        expect(downloaded.mime).toBe('application/pdf');
        expect(downloaded.text).toContain('AIDesk test offer');
        await visitor.reload();
        expect((await browserCall(visitor, `${base}/messages`, token)).body.data).toHaveLength(2);
        const second = await browserCall(visitor, `${base}/sessions`, undefined, {});
        const otherToken = second.body.data.credential;
        await browserCall(visitor, `${base}/identify`, otherToken, {name:'Тот же телефон', phone:'+79991234567', consent:true, consent_version:'contact-v1'});
        expect((await browserCall(visitor, `${base}/messages`, otherToken)).body.data).toHaveLength(0);
        await visitor.goto(`http://127.0.0.1:${ports[1]}`);
        const base2 = `/widget/v1/channels/${channels[1]}`;
        expect((await browserCall(visitor, `${base2}/messages`, token)).status).toBe(401);
        const otherSite = await browserCall(visitor, `${base2}/sessions`, undefined, {});
        expect(otherSite.status).toBe(201);
        expect((await browserCall(visitor, `${base2}/messages`, otherSite.body.data.credential)).body.data).toHaveLength(0);
    } finally {
        await visitorContext.close();
        await page.evaluate(() => (window as unknown as NoticeWindow).chatSocket.close());
    }
});
