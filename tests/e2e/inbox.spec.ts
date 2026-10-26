import { test, expect, type Page, type APIRequestContext } from '@playwright/test';

async function headers(page:Page) {
    const cookie=(await page.context().cookies()).find(cookie=>cookie.name==='XSRF-TOKEN');
    return {'Accept':'application/json','X-XSRF-TOKEN':decodeURIComponent(cookie!.value)};
}
async function login(page:Page,email:string,password:string) {
    await page.goto('/login');
    await page.getByLabel('Электронная почта').fill(email);
    await page.getByLabel('Пароль',{exact:true}).fill(password);
    await page.getByRole('button',{name:'Войти',exact:true}).click();
    await expect(page.getByRole('heading',{level:1})).toHaveText('Организация и команда');
}
async function props(page:Page) {await page.reload();return JSON.parse((await page.locator('#app').getAttribute('data-page'))!).props;}

async function clientCard(page:Page,mobile:boolean) {
    if(mobile) await page.getByRole('button',{name:'Открыть карточку клиента',exact:true}).click();
    return page.getByRole('complementary',{name:'Карточка клиента'});
}
async function closeCard(page:Page,mobile:boolean) {if(mobile)await page.getByRole('button',{name:'Закрыть карточку клиента',exact:true}).click();}

// Setup uses real HTTP endpoints; all operator handling below uses the real Inbox UI.
test('operator Inbox handles realtime messages, safe retries, notes, files, quick replies and transfer',async({page,browser,playwright},info)=>{
    test.setTimeout(120000);
    const mobile=info.project.name==='mobile';
    const suffix=`${info.project.name}-${Date.now()}`;
    const clientName=`Клиент Inbox ${suffix}`;
    const origin=`https://${info.project.name}.inbox.example.test`;
    await login(page,'e2e-owner-a@example.test','E2eOnlyPassword123');
    let own=await props(page);
    const h=await headers(page);
    let ownSite=own.sites.find((site:{origin:string})=>site.origin===origin);
    if(!ownSite){
        await page.request.post('/settings/showrooms',{headers:h,data:{name:`Inbox салон ${suffix}`},maxRedirects:0});
        own=await props(page);
        const salon=own.showrooms.find((salon:{name:string})=>salon.name===`Inbox салон ${suffix}`);
        expect((await page.request.post('/settings/sites',{headers:h,data:{name:`Inbox сайт ${suffix}`,origin,showroom_id:salon.id},maxRedirects:0})).status()).toBe(302);
        own=await props(page);ownSite=own.sites.find((site:{origin:string})=>site.origin===origin);
    }
    const available=(await(await page.request.get('/api/v1/channels')).json()).data;
    let channel=available.find((channel:{site_id:number})=>channel.site_id===ownSite.id);
    if(!channel){const response=await page.request.post('/api/v1/channels',{headers:h,data:{name:`Inbox канал ${info.project.name}`,site_id:ownSite.id}});expect(response.status()).toBe(201);channel=(await response.json()).data;}
    expect((await page.request.post('/settings/departments',{headers:h,data:{name:`Inbox отдел ${suffix}`},maxRedirects:0})).status()).toBe(302);
    own=await props(page);
    const department=own.departments.find((entry:{name:string})=>entry.name===`Inbox отдел ${suffix}`);
    const operatorName=`Оператор Inbox ${suffix}`;
    const operatorEmail=`inbox-${suffix}@example.test`;
    expect((await page.request.post('/settings/members',{headers:h,data:{name:operatorName,email:operatorEmail,password:'InboxOperator123',password_confirmation:'InboxOperator123',role:'operator',department_id:department.id},maxRedirects:0})).status()).toBe(302);
    const visitor=await playwright.request.newContext({baseURL:'http://127.0.0.1:8000',extraHTTPHeaders:{Origin:origin,Accept:'application/json'}});
    const base=`/widget/v1/channels/${channel.public_id}`;
    const start=await visitor.post(`${base}/sessions`,{data:{}});expect(start.status()).toBe(201);
    const token=(await start.json()).data.credential;
    const vh={Authorization:`Bearer ${token}`};
    expect((await visitor.post(`${base}/identify`,{headers:vh,data:{name:clientName,phone:'8 (999) 555-01-23',consent:true,consent_version:'contact-v1'}})).status()).toBe(200);
    expect((await visitor.post(`${base}/messages`,{headers:vh,data:{body:'Хочу узнать о наличии автомобиля',client_message_id:crypto.randomUUID()}})).status()).toBe(201);
    const current=(await(await visitor.get(`${base}/conversations/current`,{headers:vh})).json()).data;
    const all=(await(await page.request.get('/api/v1/conversations?q=9995550123')).json()).data;
    const conversation=all.find((entry:{public_id:string})=>entry.public_id===current.id);
    expect(conversation).toBeTruthy();
    const row=`[data-conversation-id="${conversation.id}"]`;
    try {
        await page.goto('/inbox');
        await expect(page.getByRole('heading',{name:'Диалоги',exact:true})).toBeVisible();
        await expect(page.locator(row)).toBeVisible();
        if(!mobile) await expect(page.getByText('Обновления онлайн',{exact:true})).toBeVisible();
        await expect(page.locator(row).getByLabel('Непрочитанных: 1')).toBeVisible();
        await page.locator(row).click();
        const log=page.getByRole('log',{name:'История сообщений'});
        await expect(log.getByText('Хочу узнать о наличии автомобиля',{exact:true})).toBeVisible();
        const card=await clientCard(page,mobile);
        await card.getByRole('button',{name:'Принять диалог',exact:true}).click();
        await expect(card.getByText('В работе',{exact:true})).toBeVisible();
        await closeCard(page,mobile);
        await expect(page.getByLabel('Сообщение клиенту',{exact:true})).toBeEnabled();
        const liveText=`Сообщение в реальном времени ${suffix}`;
        expect((await visitor.post(`${base}/messages`,{headers:vh,data:{body:liveText,client_message_id:crypto.randomUUID()}})).status()).toBe(201);
        await expect(log.getByText(liveText,{exact:true})).toBeVisible({timeout:12000});
        await page.getByLabel('Сообщение клиенту',{exact:true}).fill(`Ответ с файлом ${suffix}`);
        await page.getByLabel('Прикрепить файлы',{exact:true}).setInputFiles({name:'offer.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.7\nInbox offer\n%%EOF')});
        await expect(page.getByText('offer.pdf',{exact:true})).toBeVisible();
        await page.getByRole('button',{name:'Отправить',exact:true}).click();
        await expect(log.getByText(`Ответ с файлом ${suffix}`,{exact:true})).toBeVisible();
        await expect(log.getByRole('link',{name:'offer.pdf',exact:true})).toBeVisible();
        const visitorHistory=(await(await visitor.get(`${base}/messages`,{headers:vh})).json()).data;
        expect(visitorHistory.some((message:{body:string;attachments:unknown[]})=>message.body===`Ответ с файлом ${suffix}`&&message.attachments.length===1)).toBeTruthy();
        await page.getByRole('button',{name:'Быстрые ответы',exact:true}).click();
        await page.getByRole('button',{name:'Новый быстрый ответ',exact:true}).click();
        const replyTitle=`Ответ ${suffix}`;
        await page.getByLabel('Название ответа',{exact:true}).fill(replyTitle);
        await page.getByLabel('Текст быстрого ответа',{exact:true}).fill(`Подготовим предложение ${suffix}`);
        await page.getByRole('button',{name:'Сохранить ответ',exact:true}).click();
        await page.getByRole('button',{name:replyTitle,exact:true}).click();
        await expect(page.getByLabel('Сообщение клиенту',{exact:true})).toHaveValue(`Подготовим предложение ${suffix}`);
        await page.getByRole('button',{name:'Отправить',exact:true}).click();
        await expect(log.getByText(`Подготовим предложение ${suffix}`,{exact:true})).toBeVisible();
        await page.getByRole('button',{name:'Заметка',exact:true}).click();
        const note=`Не показывать клиенту ${suffix}`;
        await page.getByLabel('Текст внутренней заметки',{exact:true}).fill(note);
        await page.getByRole('button',{name:'Добавить заметку',exact:true}).click();
        await expect(log.getByText(note,{exact:true})).toBeVisible();
        expect((await(await visitor.get(`${base}/messages`,{headers:vh})).json()).data.some((message:{body:string})=>message.body===note)).toBeFalsy();
        await page.getByRole('button',{name:'Ответ клиенту',exact:true}).click();
        let interrupted=false;
        await page.route(`**/api/v1/conversations/${conversation.id}/messages`,async route=>{
            if(route.request().method()==='POST'&&!interrupted){interrupted=true;await route.fetch();await route.abort('failed');}else await route.continue();
        });
        const retriedText=`Повтор без дубля ${suffix}`;
        await page.getByLabel('Сообщение клиенту',{exact:true}).fill(retriedText);
        await page.getByRole('button',{name:'Отправить',exact:true}).click();
        await expect(page.getByRole('button',{name:'Повторить отправку',exact:true})).toBeEnabled();
        await page.getByRole('button',{name:'Повторить отправку',exact:true}).click();
        await expect(log.getByText(retriedText,{exact:true})).toHaveCount(1);
        expect((await(await visitor.get(`${base}/messages`,{headers:vh})).json()).data.filter((message:{body:string})=>message.body===retriedText)).toHaveLength(1);
        await page.unroute(`**/api/v1/conversations/${conversation.id}/messages`);
        const management=await clientCard(page,mobile);
        await management.getByRole('combobox',{name:'Отдел назначения',exact:true}).selectOption(String(department.id));
        await management.getByRole('combobox',{name:'Назначить оператора',exact:true}).selectOption({label:operatorName});
        await management.getByRole('button',{name:'Передать диалог',exact:true}).click();
        await expect(management.getByRole('definition').filter({hasText:operatorName})).toBeVisible();
        await closeCard(page,mobile);
        if(!mobile)await page.setViewportSize({width:1440,height:1000});
        if(!mobile) await page.getByRole('complementary',{name:'Карточка клиента'}).evaluate(element=>element.scrollTop=0);
        await page.screenshot({path:info.outputPath('inbox-light.png')});
        await page.getByRole('button',{name:'Включить тёмную тему',exact:true}).click();
        await expect(page.locator('html')).toHaveClass('dark');
        await page.screenshot({path:info.outputPath('inbox-dark.png')});
        expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
        const operatorContext=await browser.newContext({baseURL:'http://127.0.0.1:8000',viewport:mobile?{width:390,height:844}:{width:1440,height:1000}});
        try {
            const operatorPage=await operatorContext.newPage();
            await login(operatorPage,operatorEmail,'InboxOperator123');
            await operatorPage.goto('http://127.0.0.1:8000/inbox');
            await operatorPage.getByRole('button',{name:'Мои диалоги',exact:true}).click();
            await operatorPage.locator(row).click();
            await expect(operatorPage.getByLabel('Сообщение клиенту',{exact:true})).toBeEnabled();
            await operatorPage.getByLabel('Сообщение клиенту',{exact:true}).fill(`Ответ оператора ${suffix}`);
            await operatorPage.getByRole('button',{name:'Отправить',exact:true}).click();
            await expect(operatorPage.getByRole('log').getByText(`Ответ оператора ${suffix}`,{exact:true})).toBeVisible();
            const operatorCard=await clientCard(operatorPage,mobile);
            await operatorCard.getByLabel('Причина изменения',{exact:true}).fill('Вопрос решён');
            await operatorCard.getByRole('button',{name:'Завершить диалог',exact:true}).click();
            await expect(operatorCard.getByText('Завершён',{exact:true})).toBeVisible();
            await operatorCard.getByRole('button',{name:'Вернуть в очередь',exact:true}).click();
            await closeCard(operatorPage,mobile);
            await expect(operatorPage.getByLabel('Сообщение клиенту',{exact:true})).toHaveCount(0);
        } finally {await operatorContext.close();}
    } finally {await visitor.dispose();}
});
