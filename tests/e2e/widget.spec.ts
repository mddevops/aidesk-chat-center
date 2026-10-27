import {test,expect,type Page} from '@playwright/test';
async function headers(page:Page){return {'Accept':'application/json','X-XSRF-TOKEN':decodeURIComponent((await page.context().cookies()).find(c=>c.name==='XSRF-TOKEN')!.value)};}
test('published iframe SDK exchanges real messages with Inbox and isolates website sessions',async({page,browser},info)=>{
    test.setTimeout(120000);page.setDefaultTimeout(15000);
    const ports=info.project.name==='mobile'?[8097,8098]:[8095,8096];
    const suffix=`${info.project.name}-${Date.now()}`;
    await page.goto('/login');await page.getByLabel('Электронная почта').fill('e2e-owner-a@example.test');await page.getByLabel('Пароль',{exact:true}).fill('E2eOnlyPassword123');await page.getByRole('button',{name:'Войти',exact:true}).click();
    await expect(page.getByRole('heading',{level:1})).toHaveText('Организация и команда');
    const h=await headers(page);
    const props=async()=>{await page.goto('/settings/organization');return JSON.parse((await page.locator('#app').getAttribute('data-page'))!).props;};
    await page.request.post('/settings/showrooms',{headers:h,data:{name:`SDK ${suffix}`},maxRedirects:0});
    let data=await props();const salon=data.showrooms.find((s:{name:string})=>s.name===`SDK ${suffix}`);
    const sites:number[]=[];
    for(const port of ports){
        let site=data.sites.find((s:{origin:string})=>s.origin===`http://127.0.0.1:${port}`);
        if(!site){expect((await page.request.post('/settings/sites',{headers:h,data:{name:`SDK ${port}`,origin:`http://127.0.0.1:${port}`,showroom_id:salon.id},maxRedirects:0})).status()).toBe(302);data=await props();site=data.sites.find((s:{origin:string})=>s.origin===`http://127.0.0.1:${port}`);}
        sites.push(site.id);
    }
    await page.goto('/settings/messenger');
    let sessionRequests=0;page.on('request',r=>{if(/\/widget\/v1\/.*\/(sessions|messages|identify)$/.test(r.url()))sessionRequests++;});
    await page.getByLabel('Сайт виджета').selectOption(String(sites[0]));
    await expect(page.getByText('Предпросмотр · сообщения не отправляются')).toBeVisible();
    if(info.project.name==='mobile')await page.getByLabel('Тема',{exact:true}).selectOption('dark');
    await page.getByRole('button',{name:'Подобрать автомобиль',exact:true}).click();
    await page.getByLabel('Название',{exact:true}).fill(`Чат SDK ${suffix}`);
    await page.getByRole('button',{name:'Сохранить черновик',exact:true}).click();
    await expect(page.getByRole('status')).toContainText('Черновик сохранён');
    await page.getByRole('button',{name:'Опубликовать',exact:true}).click();await expect(page.getByRole('status')).toContainText('Настройки опубликованы');
    expect(sessionRequests).toBe(0);
    await page.getByRole('button',{name:'Установка',exact:true}).click();const code=await page.getByLabel('Код установки').inputValue();const channel=code.match(/data-channel="([^"]+)"/)![1];
    const second=(await(await page.request.post(`/api/v1/widgets/sites/${sites[1]}`,{headers:h})).json()).data;
    expect((await page.request.post(`/api/v1/widgets/${second.id}/publish`,{headers:h,data:{revision:second.revision}})).status()).toBe(200);
    await page.screenshot({path:info.outputPath('builder.png'),fullPage:true,animations:'disabled'});
    const context=await browser.newContext({viewport:info.project.use.viewport,isMobile:info.project.use.isMobile,hasTouch:info.project.use.hasTouch});
    try{
        const visitor=await context.newPage();visitor.setDefaultTimeout(15000);await visitor.goto(`http://127.0.0.1:${ports[0]}/?channel=${channel}`);
        await visitor.getByRole('button',{name:'Написать нам'}).click();
        const chat=visitor.frameLocator('iframe[title]');await expect(chat.getByText(`Чат SDK ${suffix}`,{exact:true})).toBeVisible();
        await chat.getByRole('button',{name:'Начать чат',exact:true}).click();
        await chat.getByLabel('Ваше имя').fill(`Посетитель SDK ${suffix}`);await chat.getByLabel('Телефон',{exact:true}).fill('8 (999) 987-65-43');await chat.getByRole('checkbox').check();await chat.getByRole('button',{name:'Продолжить',exact:true}).click();
        const text=`Вопрос из настоящего виджета ${suffix}`;await chat.getByLabel('Ваше сообщение').fill(text);await chat.getByRole('button',{name:'Отправить',exact:true}).click();await expect(chat.getByText(text,{exact:true})).toBeVisible();
        const retryText=`Повтор без дубля ${suffix}`;
        await chat.getByLabel('Прикрепить файл',{exact:true}).setInputFiles({name:'visitor.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.7\nWidget visitor offer\n%%EOF')});
        await expect(chat.getByText('visitor.pdf',{exact:true})).toBeVisible();
        let lost=false;
        await visitor.route('**/widget/v1/channels/*/messages',async route=>{
            if(route.request().method()==='POST'&&!lost){lost=true;await route.fetch();await route.abort('failed');}else await route.continue();
        });
        await chat.getByLabel('Ваше сообщение').fill(retryText);await chat.getByRole('button',{name:'Отправить',exact:true}).click();
        await expect(chat.getByRole('button',{name:'Повторить отправку',exact:true})).toBeEnabled();
        await chat.getByRole('button',{name:'Повторить отправку',exact:true}).click();
        await expect(chat.getByText(retryText,{exact:true})).toHaveCount(1);await expect(chat.getByRole('button',{name:'Отправить',exact:true})).toBeVisible();
        await visitor.unroute('**/widget/v1/channels/*/messages');
        await page.goto('/inbox');await page.getByRole('textbox',{name:'Поиск диалогов'}).fill(`Посетитель SDK ${suffix}`);
        const conversations=(await(await page.request.get('/api/v1/conversations?q='+encodeURIComponent(`Посетитель SDK ${suffix}`))).json()).data;expect(conversations).toHaveLength(1);
        const conversation=conversations[0];const stored=(await(await page.request.get(`/api/v1/conversations/${conversation.id}/messages`)).json()).data;expect(stored.filter((m:{body:string})=>m.body===retryText)).toHaveLength(1);await page.locator(`[data-conversation-id="${conversation.id}"]`).click();
        if(info.project.name==='mobile')await page.getByRole('button',{name:'Открыть карточку клиента',exact:true}).click();
        await page.getByRole('button',{name:'Принять диалог',exact:true}).click();
        if(info.project.name==='mobile')await page.getByRole('button',{name:'Закрыть карточку клиента',exact:true}).click();
        const reply=`Ответ в установленный виджет ${suffix}`;await page.getByLabel('Сообщение клиенту',{exact:true}).fill(reply);await page.getByRole('button',{name:'Отправить',exact:true}).click();await expect(chat.getByText(reply,{exact:true})).toBeVisible({timeout:12000});
        await visitor.reload();await visitor.getByRole('button',{name:'Написать нам'}).click();await expect(chat.getByText(reply,{exact:true})).toBeVisible();await expect(chat.getByLabel('Ваше имя')).toHaveCount(0);
        if(info.project.name==='mobile'){const box=await visitor.locator('iframe[title]').boundingBox();expect(box!.width).toBe(visitor.viewportSize()!.width);expect(box!.height).toBe(visitor.viewportSize()!.height);}
        await visitor.screenshot({path:info.outputPath('widget.png'),animations:'disabled'});
        await visitor.goto(`http://127.0.0.1:${ports[1]}/?channel=${second.channel_public_id}`);await visitor.getByRole('button',{name:'Написать нам'}).click();const other=visitor.frameLocator('iframe[title]');await expect(other.getByRole('button',{name:'Начать чат',exact:true})).toBeVisible();await expect(other.getByText(reply,{exact:true})).toHaveCount(0);
        await other.getByRole('button',{name:'Начать чат',exact:true}).click();await other.getByLabel('Ваше имя').fill('Тот же телефон');await other.getByLabel('Телефон',{exact:true}).fill('+79999876543');await other.getByRole('checkbox').check();await other.getByRole('button',{name:'Продолжить',exact:true}).click();await expect(other.getByLabel('Ваше сообщение')).toBeVisible();await expect(other.getByText(reply,{exact:true})).toHaveCount(0);
    }finally{await context.close().catch(()=>{});}
});
