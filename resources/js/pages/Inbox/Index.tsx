import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ArrowDown, Bell, BellRing, ChevronRight, Inbox, Layers3, LoaderCircle, LogOut, MessageSquare, RefreshCw, Search, Settings2, UserRound, Users, X } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { createEcho } from '@/lib/echo';
import { Button } from '@/components/ui/button';
import { ThemeToggle } from '@/components/ThemeToggle';
import { inputClass } from '@/components/Field';
import {Availability} from '@/components/inbox/Availability';
import { ClientDrawer } from '@/components/inbox/ClientDrawer';
import { ClientCard } from '@/components/inbox/ClientCard';
import { ConversationThread, type Draft } from '@/components/inbox/ConversationThread';
import { type Conversation, type Entry, type Operator, initials, statuses, time } from '@/components/inbox/types';
import type { SharedProps } from '@/lib/types';

type Props = SharedProps & {organization:Entry; operators:Operator[]; departments:Entry[]; initialConversationId:number|null};
type ListPage = {data:Conversation[]; meta:{next_cursor:string|null; has_more:boolean}};
type Notice = {event_id:string; data:{conversation_id:number; message_id?:number; sender_type?:string}};

export default function InboxPage() {
    const {auth, organization, operators, departments, initialConversationId} = usePage<Props>().props;
    const [view, setView] = useState('incoming');
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState('');
    const [channel, setChannel] = useState('');
    const [channels, setChannels] = useState<Entry[]>([]);
    const [rows, setRows] = useState<Conversation[]>([]);
    const [cursor, setCursor] = useState<string|null>(null);
    const [loading, setLoading] = useState(true);
    const [moreBusy, setMoreBusy] = useState(false);
    const [error, setError] = useState('');
    const [selected, setSelected] = useState(initialConversationId);
    const selectedRef = useRef(selected);
    const [detail, setDetail] = useState<Conversation|null>(null);
    const [cardOpen, setCardOpen] = useState(false);
    const [tick, setTick] = useState(0);
    const [connection, setConnection] = useState('Подключаемся…');
    const [toast, setToast] = useState<{text:string; id:number}|null>(null);
    const [notifications, setNotifications] = useState(typeof Notification !== 'undefined' && Notification.permission==='granted');
    const drafts = useRef(new Map<number,Draft>());
    const mounted = useRef(true);
    const listEpoch = useRef(0);
    const fetchCurrent = useRef<()=>void>(()=>{});
    const refreshTimer = useRef<number|undefined>(undefined);
    const notices = useRef(new Set<string>());
    const [listTick, setListTick] = useState(0);
    const [selectedDepartment, setSelectedDepartment] = useState('');
    const canManage = auth.permissions.includes('chat.manage');
    useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;clearTimeout(refreshTimer.current);};},[]);
    useEffect(()=>{const timer=window.setTimeout(()=>setQuery(search.trim()),250);return()=>clearTimeout(timer);},[search]);
    const choose = useCallback((id:number|null)=>{if(id!==selectedRef.current)setDetail(null);selectedRef.current=id;setSelected(id);setCardOpen(false);const url=new URL(window.location.href);if(id)url.searchParams.set('conversation',String(id));else url.searchParams.delete('conversation');window.history.replaceState(window.history.state,'',url);},[]);
    const refreshList = useCallback(()=>{clearTimeout(refreshTimer.current);refreshTimer.current=window.setTimeout(()=>{if(mounted.current)setListTick(value=>value+1);},200);},[]);
    const unavailable = useCallback(()=>{choose(null);setToast({text:'Доступ к диалогу изменился. Список обновлён.',id:0});refreshList();},[choose,refreshList]);
    const onClient = useCallback((conversation:Conversation)=>setDetail(previous=>previous&&previous.id===conversation.id&&previous.version>conversation.version?previous:conversation),[]);
    const changed = useCallback((conversation:Conversation)=>{setDetail(conversation);setView(conversation.assigned_user_id===auth.user?.id?'mine':'all');setTick(value=>value+1);refreshList();},[refreshList,auth.user?.id]);
    function params(next?:string) { const p=new URLSearchParams({view});if(query)p.set('q',query);if(status)p.set('status',status);if(channel)p.set('channel_id',channel);if(selectedDepartment)p.set('department_id',selectedDepartment);if(next)p.set('cursor',next);return p.toString(); }
    useEffect(()=>{
        const epoch=++listEpoch.current;
        const controller=new AbortController();setError('');setLoading(true);
        void api<ListPage>(`/api/v1/conversations?${params()}`,{signal:controller.signal}).then(result=>{if(mounted.current&&epoch===listEpoch.current){setRows(result.data);setCursor(result.meta.next_cursor);}}).catch(error=>{if(error.name!=='AbortError'&&mounted.current){setError(error.message);if(error instanceof ApiError&&error.status===401)window.location.assign('/login');}}).finally(()=>{if(mounted.current&&epoch===listEpoch.current)setLoading(false);});
        return()=>controller.abort();
    },[view,query,status,channel,selectedDepartment,listTick]);
    useEffect(()=>{void api<{data:Entry[]}>('/api/v1/channels').then(result=>setChannels(result.data)).catch(error=>setError(error.message));},[]);
    useEffect(()=>{
        const refresh=()=>{if(!document.hidden){refreshList();setTick(value=>value+1);}};
        fetchCurrent.current=refresh;
        const timer=window.setInterval(refresh,15000);
        document.addEventListener('visibilitychange',refresh);window.addEventListener('online',refresh);window.addEventListener('focus',refresh);
        return()=>{clearInterval(timer);document.removeEventListener('visibilitychange',refresh);window.removeEventListener('online',refresh);window.removeEventListener('focus',refresh);};
    },[refreshList]);
    useEffect(()=>{
        if(!auth.user)return;
        const echo=createEcho();
        const onNotice=(notice:Notice)=>{
            if(notices.current.has(notice.event_id))return;
            notices.current.add(notice.event_id);if(notices.current.size>500)notices.current.delete(notices.current.values().next().value!);
            refreshList();if(notice.data.conversation_id===selectedRef.current)setTick(value=>value+1);
            if(notice.data.sender_type==='visitor'&&(document.hidden||notice.data.conversation_id!==selectedRef.current)){
                setToast({text:'Новое сообщение клиента',id:notice.data.conversation_id});
                if(typeof Notification!=='undefined'&&Notification.permission==='granted'&&document.hidden){const notification=new Notification('AIDesk',{body:'Получено новое сообщение клиента',tag:notice.event_id});notification.onclick=()=>{window.focus();choose(notice.data.conversation_id);notification.close();};}
            }
        };
        echo.private(`organizations.${organization.id}.operators.${auth.user.id}`).subscribed(()=>{setConnection('Обновления онлайн');fetchCurrent.current();}).error(()=>setConnection('Нет подписки · опрос 15 с')).listen('.chat.message_available.v1',onNotice).listen('.chat.conversation_changed.v1',onNotice);
        const state=(value:{current:string})=>{setConnection(value.current==='connected'?'Подключаем диалоги…':'Переподключаемся · опрос 15 с');if(value.current==='connected')fetchCurrent.current();};
        echo.connector.pusher.connection.bind('state_change',state);
        return()=>{echo.connector.pusher.connection.unbind('state_change',state);echo.disconnect();};
    },[auth.user?.id,organization.id,choose,refreshList]);
    useEffect(()=>{if(!toast)return;const timer=window.setTimeout(()=>setToast(null),6000);return()=>clearTimeout(timer);},[toast]);
    async function loadMore(){if(!cursor)return;setMoreBusy(true);try{const result=await api<ListPage>(`/api/v1/conversations?${params(cursor)}`);setRows(previous=>Array.from(new Map([...previous,...result.data].map(row=>[row.id,row])).values()));setCursor(result.meta.next_cursor);}catch(error){setError((error as Error).message);}finally{setMoreBusy(false);}}
    const unread=rows.reduce((sum,row)=>sum+row.unread_count,0);
    const nav=[{id:'incoming',label:'Входящие',icon:Inbox},{id:'mine',label:'Мои диалоги',icon:UserRound},{id:'all',label:'Все диалоги',icon:Layers3}];
    return <><Head title={unread ? `(${unread}) Диалоги` : 'Диалоги'}/><div className="flex h-dvh min-h-0 overflow-hidden bg-background">
        <aside className="hidden w-[210px] shrink-0 flex-col border-r border-border bg-card p-4 lg:flex"><Link href="/inbox" className="flex items-center gap-2 px-2 py-2 text-xl font-bold"><span className="grid h-9 w-9 place-items-center rounded-xl bg-primary text-white"><MessageSquare size={21}/></span>AIDesk</Link><p className="mt-1 truncate px-2 text-xs text-muted-foreground">{organization.name}</p><p className="mb-2 mt-10 px-3 text-[10px] font-semibold uppercase tracking-[.16em] text-muted-foreground">Рабочее пространство</p><nav className="space-y-1">{nav.map(item=><button key={item.id} onClick={()=>{setView(item.id);choose(null);}} className={`flex w-full items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium transition-colors ${view===item.id?'bg-primary/10 text-primary':'text-muted-foreground hover:bg-muted'}`} aria-current={view===item.id?'page':undefined}><item.icon size={18}/>{item.label}</button>)}</nav>{auth.permissions.includes('chat.manage')&&<Link href="/settings/routing" className="mt-4 rounded-lg px-3 py-2 text-sm text-muted-foreground hover:bg-muted">Распределение</Link>}<Link href="/settings/organization" className="mt-5 flex items-center gap-3 rounded-xl px-3 py-3 text-sm text-muted-foreground hover:bg-muted"><Settings2 size={18}/>Настройки</Link><div className="mt-auto border-t border-border pt-4"><div className="mb-3 flex items-center gap-2 px-1"><span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-muted text-xs font-semibold">{initials(auth.user?.name||'')}</span><span className="truncate text-xs font-medium">{auth.user?.name}</span></div><div className="flex items-center justify-between"><ThemeToggle/><Button variant="ghost" size="icon" aria-label="Выйти из кабинета" onClick={()=>router.post('/logout')}><LogOut size={17}/></Button></div></div></aside>
        <div className="flex min-w-0 flex-1 flex-col"><header className="flex min-h-[68px] shrink-0 flex-wrap items-center justify-between gap-2 py-3 sm:h-[68px] sm:flex-nowrap sm:py-0 border-b border-border bg-card px-4 sm:px-6"><div><h1 className="text-xl font-semibold">Диалоги</h1><p className="hidden text-[11px] text-muted-foreground sm:block" role="status">{connection}</p></div><div className="flex items-center gap-2">{auth.permissions.includes('chat.reply')&&<Availability/>}<Button className="hidden sm:inline-flex" variant="ghost" size="icon" aria-label="Обновить диалоги" onClick={()=>fetchCurrent.current()}><RefreshCw size={17}/></Button><Button variant="outline" size="icon" aria-label={notifications?'Уведомления включены':'Включить уведомления браузера'} disabled={typeof Notification==='undefined'} onClick={async()=>{if(typeof Notification!=='undefined'){const result=await Notification.requestPermission();setNotifications(result==='granted');setToast({text:result==='granted'?'Уведомления включены':'Разрешение можно изменить в настройках браузера',id:0});}}}>{notifications?<BellRing size={17}/>:<Bell size={17}/>}</Button><span className="lg:hidden"><ThemeToggle/></span><Link href="/settings/organization" className="rounded-lg p-2 hover:bg-muted lg:hidden" aria-label="Настройки организации"><Settings2 size={19}/></Link></div></header>
        <div className="flex min-h-0 flex-1"><section className={`${selected?'hidden md:flex':'flex'} w-full shrink-0 flex-col border-r border-border bg-card md:w-[300px] 2xl:w-[340px]`} aria-label="Список диалогов"><div className="border-b border-border p-4"><div className="relative"><Search className="absolute left-3 top-3 text-muted-foreground" size={16}/><input className="h-10 w-full rounded-xl border border-border bg-background pl-9 pr-8 text-sm outline-none focus:border-primary" aria-label="Поиск диалогов" placeholder="Имя или телефон" value={search} onChange={e=>setSearch(e.target.value)}/>{search&&<button className="absolute right-2 top-3" aria-label="Очистить поиск" onClick={()=>setSearch('')}><X size={14}/></button>}</div><div className="mt-3 flex gap-1 rounded-lg bg-muted p-1 lg:hidden">{nav.map(item=><button key={item.id} aria-label={item.label} className={`min-w-0 flex-1 rounded-md px-1 py-2 text-xs ${view===item.id?'bg-card text-primary shadow-sm':'text-muted-foreground'}`} onClick={()=>setView(item.id)}>{item.id==='mine'?'Мои':item.id==='all'?'Все':'Входящие'}</button>)}</div><div className="mt-3 grid grid-cols-2 gap-2"><select aria-label="Фильтр по статусу" className="min-w-0 rounded-lg border border-border bg-background px-2 py-2 text-xs" value={status} onChange={e=>setStatus(e.target.value)}><option value="">Все статусы</option><option value="pending">В очереди</option><option value="open">В работе</option><option value="closed">Завершённые</option></select><select aria-label="Фильтр по каналу" className="min-w-0 rounded-lg border border-border bg-background px-2 py-2 text-xs" value={channel} onChange={e=>setChannel(e.target.value)}><option value="">Все каналы</option>{channels.map(row=><option key={row.id} value={row.id}>{row.name}</option>)}</select></div>{departments.length>0&&<select aria-label="Фильтр по отделу" className="mt-2 w-full rounded-lg border border-border bg-background px-2 py-2 text-xs" value={selectedDepartment} onChange={e=>setSelectedDepartment(e.target.value)}><option value="">Все доступные отделы</option>{departments.map(row=><option key={row.id} value={row.id}>{row.name}</option>)}</select>}</div><div className="min-h-0 flex-1 overflow-y-auto">{loading&&!rows.length&&<div className="grid place-items-center p-10"><LoaderCircle className="animate-spin text-muted-foreground" aria-label="Загрузка диалогов"/></div>}{error&&<p role="alert" className="p-4 text-xs text-red-600">{error}</p>}{!loading&&!rows.length&&!error&&<div className="p-8 text-center"><Inbox size={32} className="mx-auto mb-3 text-muted-foreground/50"/><p className="text-sm font-medium">Диалогов пока нет</p><p className="mt-2 text-xs leading-5 text-muted-foreground">Новые обращения появятся здесь. Попробуйте изменить фильтры.</p></div>}{rows.map(row=><button key={row.id} onClick={()=>choose(row.id)} data-conversation-id={row.id} aria-label={`Открыть диалог ${row.contact.name}`} aria-pressed={selected===row.id} className={`flex w-full items-start gap-3 border-b border-border px-4 py-4 text-left transition-colors ${selected===row.id?'border-l-2 border-l-primary bg-primary/5':'border-l-2 border-l-transparent hover:bg-muted/60'}`}><span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-muted text-xs font-semibold">{initials(row.contact.name)}</span><span className="min-w-0 flex-1"><span className="flex justify-between gap-2"><span className="truncate text-sm font-semibold">{row.contact.name}</span><time className="shrink-0 text-[10px] text-muted-foreground">{row.last_message_at?time(row.last_message_at):''}</time></span><span className="mt-1 block truncate text-xs text-muted-foreground">{row.preview_kind==='note'?'Заметка: ':''}{row.preview||'Вложение'}</span><span className="mt-2 flex items-center justify-between gap-2"><span className="truncate text-[10px] text-muted-foreground">{row.channel.name} · {statuses[row.status]}</span>{row.unread_count>0&&<span className="grid min-w-5 place-items-center rounded-full bg-primary px-1 py-0.5 text-[10px] text-white" aria-label={`Непрочитанных: ${row.unread_count}`}>{row.unread_count}</span>}</span></span></button>)}{cursor&&<div className="p-4"><Button variant="outline" className="w-full" disabled={moreBusy} onClick={()=>void loadMore()}><ArrowDown size={14}/>Ещё диалоги</Button></div>}</div><div className="border-t border-border px-4 py-3 text-[10px] text-muted-foreground">В списке: {rows.length} · Непрочитано: {unread}</div></section>
        <main className={`${selected?'flex':'hidden md:flex'} min-h-0 min-w-0 flex-1 flex-col`}>{selected?<ConversationThread key={selected} id={selected} refreshTick={tick} canManage={canManage} drafts={drafts} onBack={()=>choose(null)} onClient={onClient} onCard={()=>setCardOpen(true)} onChange={refreshList} onUnavailable={unavailable}/>:<div className="flex flex-1 flex-col items-center justify-center px-8 text-center"><div className="mb-6 grid h-20 w-20 place-items-center rounded-3xl border border-border bg-card shadow-sm"><MessageSquare size={34} className="text-primary"/></div><h2 className="text-xl font-semibold">Каждый диалог важен</h2><p className="mt-3 max-w-xs text-sm leading-6 text-muted-foreground">Выберите обращение слева, чтобы прочитать сообщения и помочь клиенту.</p></div>}</main>
        {selected&&detail&&<div className="hidden w-[285px] shrink-0 border-l border-border xl:block"><ClientCard key={detail.id} conversation={detail} operators={operators} departments={departments} onChanged={changed} onConflict={()=>setTick(value=>value+1)} onSearch={phone=>{setSearch(phone);setView('all');choose(null);}} onClose={()=>setCardOpen(false)}/></div>}
        </div></div>
        {cardOpen&&selected&&detail&&<ClientDrawer onClose={()=>setCardOpen(false)}><ClientCard key={detail.id} conversation={detail} operators={operators} departments={departments} onChanged={changed} onConflict={()=>setTick(value=>value+1)} onSearch={phone=>{setSearch(phone);setView('all');choose(null);}} onClose={()=>setCardOpen(false)}/></ClientDrawer>}
        {toast&&<div role="status" className="fixed bottom-5 right-4 z-50 flex max-w-[calc(100vw-32px)] items-center gap-3 rounded-xl border border-border bg-card px-4 py-3 text-sm shadow-xl"><button className="text-left" onClick={()=>{if(toast.id)choose(toast.id);setToast(null);}}>{toast.text}</button><button aria-label="Закрыть уведомление" onClick={()=>setToast(null)}><X size={15}/></button></div>}
    </div></>;
}
