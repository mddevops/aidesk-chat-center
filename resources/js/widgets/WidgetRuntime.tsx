import {useEffect, useRef, useState, type CSSProperties, type FormEvent} from 'react';
import type {WidgetConfig} from './types';

type Message = {created_at:string;id:number;sequence:number;body:string;sender_type:string;operator_name:string|null;attachments:{id:number;name:string}[]};
type Pending = {client_message_id:string;body:string;attachment_ids:number[]};
export function WidgetRuntime({configuration:c, channel='', parentOrigin='', preview=false}: {configuration:WidgetConfig;channel?:string;parentOrigin?:string;preview?:boolean}) {
    const [credential, setCredential] = useState<string|null>(null);
    const token = useRef<string|null>(null);
    const [identified, setIdentified] = useState(false);
    const [started, setStarted] = useState(false);
    const [messages, setMessages] = useState<Message[]>([]);
    const [body, setBody] = useState('');
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [department,setDepartment]=useState('');
    const [consent, setConsent] = useState(false);
    const [error, setError] = useState('');
    const [networkError,setNetworkError]=useState('');
    const [busy, setBusy] = useState(false);
    const [closed, setClosed] = useState(false);
    const [pending, setPending] = useState<Pending|null>(null);
    const [files, setFiles] = useState<{id:number;name:string}[]>([]);
    const [dark, setDark] = useState(c.theme === 'dark' || (c.theme === 'auto' && matchMedia('(prefers-color-scheme:dark)').matches));
    const active = useRef(true);
    const cursor = useRef(0), historyBusy = useRef(false), scroll = useRef<HTMLDivElement>(null), atBottom = useRef(true);
    const endpoint = '/widget/v1/channels/' + channel;
    function post(type:string) { if (!preview) parent.postMessage({type}, parentOrigin); }
    async function request(path:string, method='GET', data?:unknown):Promise<any> {
        const form = data instanceof FormData;
        const response = await fetch(endpoint + path, {method,credentials:'omit',signal:AbortSignal.timeout(15000),headers:{Accept:'application/json',Authorization:'Bearer '+token.current,...(data !== undefined && !form ? {'Content-Type':'application/json'} : {})},body:data === undefined ? undefined : form ? data : JSON.stringify(data)});
        const result = await response.json();
        if (!response.ok) {
            if (response.status === 401) {token.current=null;setCredential(null);setStarted(false);setIdentified(false);setMessages([]);cursor.current=0;setPending(null);setFiles([]);post('aidesk:forget');}
            const failure = new Error(result.errors ? Object.values(result.errors).flat().join(' ') : result.error?.message || 'Не удалось выполнить запрос.') as Error & {status:number};
            failure.status=response.status;throw failure;
        }
        return result;
    }
    async function recover() {
        if (preview || !token.current || historyBusy.current) return;
        historyBusy.current = true;
        const recoveringToken=token.current;
        try {
            const current = await request('/conversations/current');
            if(recoveringToken!==token.current)return;
            setIdentified(current.identified); setClosed(current.data?.status === 'closed');
            let more = true;
            while (more && token.current) {
                const result = await request('/messages?after_sequence=' + cursor.current);
                if(recoveringToken!==token.current)return;
                setMessages(old => {const map = new Map(old.map(m => [m.id,m])); for(const m of result.data) map.set(m.id,m); return [...map.values()].sort((a,b)=>a.sequence-b.sequence);});
                cursor.current = result.meta.next_after_sequence;
                more = result.meta.has_more;
            }
            if (active.current && atBottom.current && document.visibilityState === 'visible' && cursor.current) await request('/read','POST',{last_read_sequence:cursor.current});
            setNetworkError('');
        } catch (e) {setNetworkError(e instanceof Error ? e.message : 'Соединение прервано. Повторяем подключение…');}
        finally {historyBusy.current=false;}
    }
    useEffect(() => {
        const media=matchMedia('(prefers-color-scheme:dark)');
        const update=()=>setDark(c.theme==='dark'||(c.theme==='auto'&&media.matches));update();media.addEventListener('change',update);return()=>media.removeEventListener('change',update);
    },[c.theme]);
    useEffect(() => {
        if (preview) return;
        const receive=(event:MessageEvent)=>{
            if(event.origin!==parentOrigin||event.source!==parent||!event.data)return;
            if(event.data.type==='aidesk:credential') {
                const value=event.data.credential;
                if(typeof value==='string'&&/^[a-f0-9]{64}$/.test(value)) {token.current=value;setCredential(value);setStarted(true);}
            }
            if(event.data.type==='aidesk:error') {setBusy(false);setStarted(false);setError('Не удалось начать чат. Попробуйте ещё раз.');}
            if(event.data.type==='aidesk:visible') {active.current=event.data.visible===true;if(active.current)void recover();}
        };
        window.addEventListener('message',receive);post('aidesk:ready');
        return()=>window.removeEventListener('message',receive);
    },[preview,parentOrigin]);
    useEffect(()=>{
        if(preview||!credential)return;
        setBusy(false);void recover();
        const interval=window.setInterval(()=>{if(active.current&&document.visibilityState==='visible')void recover();},4000);
        const restore=()=>void recover();window.addEventListener('online',restore);window.addEventListener('focus',restore);
        return()=>{clearInterval(interval);window.removeEventListener('online',restore);window.removeEventListener('focus',restore);};
    },[credential,preview]);
    useEffect(()=>{if(atBottom.current&&scroll.current)scroll.current.scrollTop=scroll.current.scrollHeight;},[messages]);
    async function identify(event:FormEvent) {
        event.preventDefault();if(preview)return;setBusy(true);setError('');
        try{await request('/identify','POST',{name,phone,department_id:department?Number(department):null,consent,consent_version:'contact-v1'});setIdentified(true);}catch(e){setError(e instanceof Error?e.message:'Не удалось сохранить контакт.');}finally{setBusy(false);}
    }
    async function send(event:FormEvent) {
        event.preventDefault();if(preview||busy||closed)return;
        const payload=pending||{client_message_id:crypto.randomUUID(),body:body.trim(),attachment_ids:files.map(f=>f.id)};
        if(!payload.body&&!payload.attachment_ids.length)return;
        setPending(payload);setBusy(true);setError('');
        try{const result=await request('/messages','POST',payload);setMessages(old=>[...old.filter(m=>m.id!==result.data.id),result.data].sort((a,b)=>a.sequence-b.sequence));setBody('');setFiles([]);setPending(null);atBottom.current=true;await recover();}
        catch(e){const status=(e as Error & {status?:number}).status;if(status&&status>=400&&status<500&&status!==408&&status!==429)setPending(null);setError(e instanceof Error?e.message:'Не удалось отправить. Повторите отправку.');}finally{setBusy(false);}
    }
    const style:CSSProperties = {height:'100%',display:'flex',flexDirection:'column',background:dark?'#171923':'#fff',color:dark?'#f5f5fa':'#222330',fontFamily:c.font==='serif'?'Georgia,serif':c.font==='sans'?'Arial,sans-serif':'system-ui,sans-serif',fontSize:14,overflow:'hidden',borderRadius:preview?c.radius:0,...({'--accent':c.color} as CSSProperties)};
    const field:CSSProperties={width:'100%',boxSizing:'border-box',padding:'12px',border:'1px solid '+(dark?'#444655':'#dddde7'),borderRadius:10,background:dark?'#242633':'#fff',color:'inherit',font:'inherit'};
    const button:CSSProperties={...field,background:c.color,color:'#fff',cursor:'pointer',border:0,fontWeight:600};
    return <section style={style} aria-label="Чат автосалона">
        <style>{`html,body,#widget-root{height:100%;margin:0}*{box-sizing:border-box}button:disabled{opacity:.55;cursor:default}input:focus-visible,textarea:focus-visible,button:focus-visible,a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}@media(prefers-reduced-motion:no-preference){.widget-animate{animation:appear .2s ease-out}@keyframes appear{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:translateY(0)}}}`}</style>
        <header style={{display:'flex',alignItems:'center',gap:10,padding:'18px',borderBottom:'1px solid '+(dark?'#333541':'#eeeef5')}}>
            {c.logo_url?<img src={c.logo_url} referrerPolicy="no-referrer" alt="" width={36} height={36} style={{borderRadius:10,objectFit:'contain'}}/>:c.avatars&&<span aria-hidden="true" style={{background:c.color,color:'#fff',padding:10,borderRadius:12}}>А</span>}
            <div style={{flex:1,minWidth:0}}><strong style={{display:'block',overflowWrap:'anywhere'}}>{c.name}</strong><small style={{opacity:.65}}>{c.mode==='online'?'Оставьте вопрос — мы поможем':'Ответим в рабочее время'}</small></div>
            <button type="button" onClick={()=>post('aidesk:close')} aria-label="Закрыть чат" style={{background:'none',color:'inherit',border:0,fontSize:24,cursor:'pointer'}}>×</button>
        </header>
        {preview&&<div style={{padding:8,textAlign:'center',background:c.color+'20',fontSize:12}}>Предпросмотр · сообщения не отправляются</div>}
        <div ref={scroll} onScroll={()=>{const el=scroll.current!;atBottom.current=el.scrollHeight-el.scrollTop-el.clientHeight<40;}} style={{flex:1,overflowY:'auto',padding:18}}>
            <p style={{lineHeight:1.6,whiteSpace:'pre-wrap',overflowWrap:'anywhere'}}>{c.mode==='offline'?c.offline_message:c.greeting}</p>
            {!started&&<><div style={{display:'flex',flexWrap:'wrap',gap:8,margin:'18px 0'}}>{c.buttons.map((text,i)=><button key={i} style={{...field,width:'auto',cursor:'pointer'}} onClick={()=>{setBody(text);if(!preview){setStarted(true);setBusy(true);post('aidesk:start');}}}>{text}</button>)}</div><button style={button} disabled={busy||preview} onClick={()=>{setStarted(true);setBusy(true);post('aidesk:start');}}>Начать чат</button></>}
            {started&&!identified&&credential&&<form onSubmit={identify} style={{display:'grid',gap:12,marginTop:20}}><strong>Как к вам обращаться?</strong><label>Ваше имя<input style={{...field,marginTop:5}} required maxLength={150} autoComplete="name" value={name} onChange={e=>setName(e.target.value)}/></label><label>Телефон<input style={{...field,marginTop:5}} type="tel" required maxLength={50} autoComplete="tel" value={phone} onChange={e=>setPhone(e.target.value)}/></label>{(c.departments?.length||0)>0&&<label>Отдел<select style={field} required value={department} onChange={e=>setDepartment(e.target.value)}><option value="">Выберите отдел</option>{c.departments!.map(d=><option key={d.id} value={d.id}>{d.name}</option>)}</select></label>}<label style={{display:'flex',alignItems:'flex-start',gap:8,fontSize:12,lineHeight:1.5}}><input type="checkbox" required checked={consent} onChange={e=>setConsent(e.target.checked)}/><span>{c.consent_text}{c.privacy_url&&<> <a href={c.privacy_url} target="_blank" rel="noopener noreferrer" style={{color:'inherit'}}>Политика конфиденциальности</a></>}</span></label><button style={button} disabled={busy}>Продолжить</button><small style={{opacity:.65}}>История доступна только в этом браузере. Телефон не даёт доступ к чужим обращениям.</small></form>}
            <div aria-live="polite" aria-label="Сообщения">{messages.map(m=><article key={m.id} className={c.animation?'widget-animate':''} style={{margin:'12px 0',marginLeft:m.sender_type==='visitor'?28:0,marginRight:m.sender_type==='visitor'?0:28,padding:12,borderRadius:14,background:m.sender_type==='visitor'?c.color+'20':dark?'#292b39':'#f2f3f8',overflowWrap:'anywhere'}}><small style={{opacity:.65}}>{m.sender_type==='visitor'?'Вы':m.operator_name||'Консультант'}</small><div style={{whiteSpace:'pre-wrap',lineHeight:1.6,marginTop:4}}>{m.body}</div>{m.attachments.map(f=><button key={f.id} style={{...field,marginTop:8,cursor:'pointer'}} onClick={async()=>{try{const r=await fetch(endpoint+'/attachments/'+f.id,{credentials:'omit',headers:{Authorization:'Bearer '+token.current}});if(!r.ok)throw new Error();const url=URL.createObjectURL(await r.blob());const a=document.createElement('a');a.href=url;a.download=f.name;a.click();setTimeout(()=>URL.revokeObjectURL(url),60000);}catch{setError('Не удалось скачать файл.');}}}>↓ {f.name}</button>)}<small style={{opacity:.55,display:'block',marginTop:6}}>{new Date(m.created_at).toLocaleTimeString('ru',{hour:'2-digit',minute:'2-digit'})}{m.sender_type==='visitor'?' · Сохранено':''}</small></article>)}</div>
        </div>
        {(error||networkError)&&<div role="alert" style={{padding:'10px 18px',color:dark?'#ffb4b4':'#a51b30',fontSize:12}}>{error||networkError}</div>}
        {started&&identified&&<form onSubmit={send} style={{padding:14,borderTop:'1px solid '+(dark?'#333541':'#eeeef5'),display:'grid',gap:8}}>{closed?<p>Диалог завершён оператором.</p>:<><textarea aria-label="Ваше сообщение" style={{...field,resize:'vertical',minHeight:70,maxHeight:140}} maxLength={10000} value={body} disabled={busy||!!pending} onChange={e=>setBody(e.target.value)} placeholder="Напишите сообщение…" onKeyDown={e=>{if(e.key==='Enter'&&(e.ctrlKey||e.metaKey)){e.preventDefault();void send(e);}}}/>{files.map(f=><small key={f.id}>{f.name}</small>)}<div style={{display:'flex',gap:8}}><label style={{...field,width:44,textAlign:'center',cursor:'pointer'}} title="Прикрепить файл">＋<input aria-label="Прикрепить файл" type="file" hidden accept="image/jpeg,image/png,image/webp,application/pdf" disabled={busy||!!pending||files.length>=5} onChange={async e=>{const file=e.target.files?.[0];e.target.value='';if(!file)return;setBusy(true);try{const data=new FormData();data.append('file',file);const result=await request('/attachments','POST',data);setFiles(old=>[...old,result.data]);}catch(err){setError(err instanceof Error?err.message:'Ошибка загрузки.');}finally{setBusy(false);}}}/></label><button style={button} disabled={busy||(!body.trim()&&!files.length)}>{pending?'Повторить отправку':'Отправить'}</button></div></>}</form>}
        {credential&&<button style={{background:'none',border:0,color:'inherit',opacity:.6,fontSize:11,padding:10,cursor:'pointer'}} disabled={busy} onClick={async()=>{try{await request('/revoke','POST');post('aidesk:forget');token.current=null;setCredential(null);setStarted(false);setIdentified(false);setMessages([]);setBody('');setFiles([]);setPending(null);cursor.current=0;}catch{setError('Не удалось завершить сессию.');}}}>Завершить сессию на этом устройстве</button>}
    </section>;
}
