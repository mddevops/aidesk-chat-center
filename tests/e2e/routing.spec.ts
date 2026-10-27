import {test,expect,type Page} from '@playwright/test';
async function login(page:Page,email:string,password:string){await page.goto('/login');await page.getByLabel('Электронная почта').fill(email);await page.getByLabel('Пароль',{exact:true}).fill(password);await page.getByRole('button',{name:'Войти',exact:true}).click();await expect(page.getByRole('heading',{level:1})).toHaveText('Организация и команда');}
async function headers(page:Page){return {'Accept':'application/json','X-XSRF-TOKEN':decodeURIComponent((await page.context().cookies()).find(c=>c.name==='XSRF-TOKEN')!.value)};}
test('manager configures routing and an available operator receives an assigned conversation',async({page,browser,playwright},info)=>{
    test.setTimeout(90000);page.setDefaultTimeout(15000);
    const suffix=`${info.project.name}-${Date.now()}`;
    await login(page,'e2e-owner-a@example.test','E2eOnlyPassword123');const h=await headers(page);
    await page.request.post('/settings/showrooms',{headers:h,data:{name:`Routing ${suffix}`},maxRedirects:0});await page.reload();let props=JSON.parse((await page.locator('#app').getAttribute('data-page'))!).props;const salon=props.showrooms.find((s:{name:string})=>s.name===`Routing ${suffix}`);
    const origin=`https://routing-${suffix}.example.test`;
    expect((await page.request.post('/settings/sites',{headers:h,data:{name:`Routing ${suffix}`,origin,showroom_id:salon.id},maxRedirects:0})).status()).toBe(302);await page.reload();props=JSON.parse((await page.locator('#app').getAttribute('data-page'))!).props;const site=props.sites.find((s:{origin:string})=>s.origin===origin);
    const channel=(await(await page.request.post('/api/v1/channels',{headers:h,data:{site_id:site.id,name:`Routing ${suffix}`}})).json()).data;
    const name=`Маршрутизация ${suffix}`,email=`routing-${suffix}@example.test`;
    expect((await page.request.post('/settings/members',{headers:h,data:{name,email,password:'RoutingOperator123',password_confirmation:'RoutingOperator123',role:'operator',department_id:null},maxRedirects:0})).status()).toBe(302);
    await page.goto('/settings/routing');await page.getByLabel('Канал распределения').selectOption(String(channel.id));await page.getByLabel('Способ назначения').selectOption('least_load');await page.getByRole('checkbox',{name,exact:true}).check();await page.getByRole('button',{name:'Сохранить правило',exact:true}).click();await expect(page.getByRole('status')).toContainText('Правило сохранено');
    await page.getByLabel('Оператор расписания').selectOption({label:name});await page.getByLabel('Одновременных диалогов').fill('1');await page.getByRole('button',{name:'Сохранить расписание',exact:true}).click();await expect(page.getByRole('status')).toContainText('расписание сохранены');
    await page.screenshot({path:info.outputPath('routing-settings.png'),fullPage:true});
    const context=await browser.newContext({baseURL:'http://127.0.0.1:8000',viewport:info.project.use.viewport,isMobile:info.project.use.isMobile,hasTouch:info.project.use.hasTouch});
    const visitor=await playwright.request.newContext({baseURL:'http://127.0.0.1:8000',extraHTTPHeaders:{Origin:origin,Accept:'application/json'}});
    try{
        const operator=await context.newPage();await login(operator,email,'RoutingOperator123');await operator.goto('/inbox');await expect(operator.getByLabel('Доступность оператора')).toBeEnabled();await operator.getByLabel('Доступность оператора').selectOption('available');await expect.poll(async()=> (await(await operator.request.get('/api/v1/availability')).json()).data.status).toBe('available');
        const base=`/widget/v1/channels/${channel.public_id}`;
        async function send(client:string){const token=(await(await visitor.post(base+'/sessions',{data:{}})).json()).data.credential;const vh={Authorization:`Bearer ${token}`};expect((await visitor.post(base+'/identify',{headers:vh,data:{name:client,phone:'89991230000',consent:true,consent_version:'contact-v1'}})).status()).toBe(200);expect((await visitor.post(base+'/messages',{headers:vh,data:{body:`Вопрос ${client}`,client_message_id:crypto.randomUUID()}})).status()).toBe(201);}
        await send(`Клиент ${suffix}`);
        await operator.getByRole('button',{name:'Мои диалоги',exact:true}).click();await operator.getByRole('button',{name:`Открыть диалог Клиент ${suffix}`,exact:true}).click();await expect(operator.getByLabel('Сообщение клиенту',{exact:true})).toBeEnabled();
        await operator.getByLabel('Сообщение клиенту',{exact:true}).fill('Принято автоматически');await operator.getByRole('button',{name:'Отправить',exact:true}).click();await expect(operator.getByRole('log').getByText('Принято автоматически',{exact:true})).toBeVisible();
        await send(`Очередь ${suffix}`);const pending=(await(await page.request.get('/api/v1/conversations?q='+encodeURIComponent(`Очередь ${suffix}`))).json()).data;expect(pending).toHaveLength(1);expect(pending[0].assigned_user_id).toBeNull();expect(pending[0].status).toBe('pending');
        await operator.getByLabel('Доступность оператора').selectOption('away');await expect.poll(async()=>(await(await operator.request.get('/api/v1/availability')).json()).data.status).toBe('away');
    }finally{await visitor.dispose();await context.close();}
});
