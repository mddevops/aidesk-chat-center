import { useCallback, useEffect, useLayoutEffect, useRef, useState, type MutableRefObject } from 'react';
import { ArrowLeft, Check, CheckCheck, FileText, LoaderCircle, Paperclip, Send, StickyNote, X } from 'lucide-react';
import { api, ApiError } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { QuickReplies } from './QuickReplies';
import { type Attachment, type Conversation, type Message, type MessagePage, initials, statuses, time } from './types';

type Pending = {client_message_id: string; body: string; kind: 'message' | 'note'; attachment_ids: number[]};
export type Draft = {body: string; kind: 'message' | 'note'; files: Attachment[]; pending: Pending | null};
export function ConversationThread({id, refreshTick, canManage, drafts, onBack, onClient, onCard, onChange, onUnavailable}: {id:number; refreshTick:number; canManage:boolean; drafts:MutableRefObject<Map<number,Draft>>; onBack:()=>void; onCard:()=>void; onClient:(conversation:Conversation)=>void; onChange:()=>void; onUnavailable:()=>void}) {
    const initial = drafts.current.get(id);
    const [conversation, setConversation] = useState<Conversation | null>(null);
    const [messages, setMessages] = useState<Message[]>([]);
    const [body, setBody] = useState(initial?.body || '');
    const [kind, setKind] = useState<'message'|'note'>(initial?.kind || 'message');
    const [files, setFiles] = useState<Attachment[]>(initial?.files || []);
    const [pending, setPending] = useState<Pending | null>(initial?.pending || null);
    const [sending, setSending] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(true);
    const [hasOlder, setHasOlder] = useState(false);
    const [olderBusy, setOlderBusy] = useState(false);
    const [visible, setVisible] = useState(!document.hidden);
    const [atBottom, setAtBottom] = useState(true);
    const list = useRef<HTMLDivElement>(null);
    const endSequence = useRef(0);
    const follow = useRef(true);
    const initialized = useRef(false);
    const busy = useRef(false);
    const mounted = useRef(true);
    const marked = useRef(0);
    const fileInput = useRef<HTMLInputElement>(null);
    useEffect(() => { mounted.current = true; return () => {mounted.current = false;}; }, []);
    useEffect(() => {drafts.current.set(id, {body, kind, files, pending});}, [id, body, kind, files, pending, drafts]);
    const refresh = useCallback(async () => {
        if (busy.current || document.hidden) return;
        busy.current = true;
        try {
            const detail = (await api<{data:Conversation}>(`/api/v1/conversations/${id}`)).data;
            let cursor = endSequence.current;
            let accumulated: Message[] = [];
            let more = true;
            while (more && mounted.current) {
                const result = await api<MessagePage>(`/api/v1/conversations/${id}/messages?${initialized.current ? `after_sequence=${cursor}` : 'latest=1'}`);
                accumulated = accumulated.concat(result.data);
                cursor = result.meta.next_after_sequence;
                more = result.meta.has_more;
                if (!initialized.current && mounted.current) setHasOlder(result.meta.has_older);
                if (!initialized.current) break;
            }
            if (!mounted.current) return;
            endSequence.current = cursor;
            initialized.current = true;
            setConversation(previous=>previous&&previous.version>detail.version?previous:detail);
            onClient(detail);
            setMessages(previous => Array.from(new Map([...previous, ...accumulated].map(message => [message.id, message])).values()).sort((a,b) => a.sequence-b.sequence));
            setLoading(false);
        } catch (error) {
            if (!mounted.current) return;
            if (error instanceof ApiError && [403,404].includes(error.status)) {setMessages([]); onUnavailable();}
            else setError((error as Error).message);
            setLoading(false);
        } finally {busy.current = false;}
    }, [id, onClient, onUnavailable]);
    useEffect(() => { void refresh(); }, [refresh, refreshTick]);
    useEffect(() => {
        const timer = window.setInterval(() => void refresh(), 15000);
        const show = () => {setVisible(!document.hidden); if (!document.hidden) void refresh();};
        document.addEventListener('visibilitychange', show);
        window.addEventListener('online', show);
        return () => {clearInterval(timer); document.removeEventListener('visibilitychange', show); window.removeEventListener('online', show);};
    }, [refresh]);
    useLayoutEffect(() => {if (follow.current && list.current) list.current.scrollTop = list.current.scrollHeight;}, [messages]);
    useEffect(() => {
        const sequence = messages.at(-1)?.sequence || 0;
        if (!conversation?.permissions.read || !visible || !atBottom || !sequence || sequence <= marked.current) return;
        marked.current = sequence;
        void api(`/api/v1/conversations/${id}/read`, {method:'POST', body:{last_read_sequence:sequence}}).then(onChange).catch(() => {marked.current = 0;});
    }, [conversation?.permissions.read, messages, visible, atBottom, id, onChange]);
    async function loadOlder() {
        if (!messages.length) return;
        setOlderBusy(true); setAtBottom(false);
        try {
            const height = list.current?.scrollHeight || 0;
            const top = list.current?.scrollTop || 0;
            const result = await api<MessagePage>(`/api/v1/conversations/${id}/messages?before_sequence=${messages[0].sequence}`);
            follow.current = false;
            setHasOlder(result.meta.has_older);
            setMessages(previous => Array.from(new Map([...result.data,...previous].map(message => [message.id,message])).values()).sort((a,b)=>a.sequence-b.sequence));
            requestAnimationFrame(() => {if (list.current) list.current.scrollTop = list.current.scrollHeight-height+top;});
        } catch(error) {setError((error as Error).message);} finally {setOlderBusy(false);}
    }
    async function send() {
        if (sending || uploading || !conversation?.permissions.reply) return;
        const snapshot = pending || {client_message_id:crypto.randomUUID(), body:body.trim(), kind, attachment_ids:files.map(file=>file.id)};
        setPending(snapshot); setSending(true); setError('');
        try {
            await api(`/api/v1/conversations/${id}/messages`, {method:'POST', body:snapshot});
            if (!mounted.current) return;
            setBody(''); setFiles([]); setPending(null);
            follow.current = true; setAtBottom(true);
            await refresh(); onChange();
        } catch(error) {if (mounted.current) setError((error as Error).message);}
        finally {if (mounted.current) setSending(false);}
    }
    async function upload(chosen: FileList | null) {
        if (!chosen) return;
        if (chosen.length+files.length > 5) {setError('Можно прикрепить до 5 файлов.'); return;}
        setUploading(true); setError('');
        try {
            for (const file of Array.from(chosen)) {
                if (file.size > 10*1024*1024) throw new Error('Файл должен быть не больше 10 МБ.');
                const form = new FormData(); form.append('file',file);
                const result = await api<{data:Attachment}>('/api/v1/attachments', {method:'POST', body:form});
                if (mounted.current) setFiles(previous => [...previous,result.data]);
            }
        } catch(error) {setError((error as Error).message);} finally {setUploading(false); if (fileInput.current) fileInput.current.value='';}
    }
    return <section className="flex h-full min-h-0 min-w-0 flex-col bg-background" aria-label="Переписка">
        <header className="flex h-[76px] shrink-0 items-center justify-between gap-3 border-b border-border bg-card px-4 sm:px-6"><div className="flex min-w-0 items-center gap-3"><Button variant="ghost" size="icon" className="md:hidden" aria-label="К списку диалогов" onClick={onBack}><ArrowLeft size={19}/></Button><div className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary/10 text-sm font-semibold text-primary">{conversation ? initials(conversation.contact.name) : '…'}</div><div className="min-w-0"><h2 className="truncate font-semibold">{conversation?.contact.name || 'Загрузка диалога…'}</h2><p className="truncate text-xs text-muted-foreground">{conversation?.channel.name}{conversation && ` · № ${conversation.id}`}</p></div></div>{conversation && <button type="button" className="shrink-0" aria-label="Открыть карточку клиента" onClick={onCard}><Badge variant="primary-light">{statuses[conversation.status]}</Badge></button>}</header>
        {conversation && !conversation.permissions.reply && conversation.status !== 'closed' && <div role="status" className="border-b border-border bg-card px-5 py-2 text-xs text-muted-foreground">{conversation.permissions.accept ? 'Примите диалог, чтобы ответить клиенту.' : 'Режим наблюдения. Отправка и отметка прочтения недоступны.'}</div>}
        <div ref={list} className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-5 sm:px-7" role="log" aria-label="История сообщений" aria-live="polite" onScroll={() => {const el=list.current; if (el) {const bottom=el.scrollHeight-el.scrollTop-el.clientHeight<60; follow.current=bottom; setAtBottom(bottom);}}}>
            {loading && <div className="flex justify-center p-8 text-muted-foreground"><LoaderCircle className="animate-spin" aria-label="Загружаем сообщения"/></div>}
            {hasOlder && <div className="mb-5 text-center"><Button variant="outline" disabled={olderBusy} onClick={()=>void loadOlder()}>Ранние сообщения</Button></div>}
            <div className="space-y-5">{messages.map(message => <article key={message.id} data-message-id={message.id} className={`flex ${message.kind === 'note' ? 'justify-center' : message.sender_type === 'operator' ? 'justify-end' : 'justify-start'}`}><div className={`max-w-[92%] rounded-2xl px-4 py-3 text-sm sm:max-w-[80%] ${message.kind==='note' ? 'w-full border border-amber-300/50 bg-amber-100/70 text-amber-950 dark:bg-amber-900/20 dark:text-amber-100' : message.sender_type === 'operator' ? 'rounded-br-sm bg-primary text-white' : 'rounded-bl-sm border border-border bg-card'}`}><div className="mb-1 flex items-center gap-1 text-[11px] opacity-75">{message.kind==='note' && <StickyNote size={12}/>}<span>{message.kind==='note' ? 'Внутренняя заметка' : message.sender_type==='visitor' ? conversation?.contact.name : message.operator_name || 'Команда'}</span></div><p className="whitespace-pre-wrap break-words [overflow-wrap:anywhere]">{message.body}</p>{message.attachments.map(file=><a key={file.id} className="mt-2 flex max-w-full items-center gap-2 rounded-lg border border-current/20 p-2 underline-offset-2 hover:underline" href={`/api/v1/attachments/${file.id}`} download><FileText size={17} className="shrink-0"/><span className="min-w-0 break-all">{file.name}</span></a>)}<div className="mt-2 flex justify-end gap-1 text-[10px] opacity-65"><time dateTime={message.created_at} title={new Date(message.created_at).toLocaleString('ru')}>{time(message.created_at)}</time>{message.sender_type==='operator' && <Check size={12} aria-label="Сохранено на сервере"/>}</div></div></article>)}</div>
        </div>
        {error && <div role="alert" className="flex items-start justify-between gap-2 border-t border-red-500/20 bg-card px-5 py-2 text-xs text-red-600 dark:text-red-400"><span>{error}</span><button onClick={()=>setError('')} aria-label="Скрыть ошибку"><X size={15}/></button></div>}
        {conversation?.permissions.reply ? <form onSubmit={e=>{e.preventDefault(); void send();}} className={`shrink-0 border-t border-border p-3 pb-[max(12px,env(safe-area-inset-bottom))] sm:p-4 ${kind==='note' ? 'bg-amber-50 dark:bg-amber-950/20' : 'bg-card'}`}><div className="mb-2 flex gap-2"><button type="button" disabled={!!pending} className={`rounded-md px-3 py-1 text-xs ${kind==='message' ? 'bg-primary/10 text-primary' : 'text-muted-foreground'}`} onClick={()=>setKind('message')}>Ответ клиенту</button><button type="button" disabled={!!pending} className={`rounded-md px-3 py-1 text-xs ${kind==='note' ? 'bg-amber-200 text-amber-950' : 'text-muted-foreground'}`} onClick={()=>setKind('note')}>Заметка</button></div><textarea aria-label={kind==='note' ? 'Текст внутренней заметки' : 'Сообщение клиенту'} rows={3} maxLength={10000} disabled={sending || !!pending} className="max-h-44 min-h-20 w-full resize-y rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary disabled:opacity-60" placeholder={kind==='note' ? 'Видно только вашей команде…' : 'Напишите ответ клиенту…'} value={body} onChange={e=>setBody(e.target.value)} onKeyDown={e=>{if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){e.preventDefault(); void send();}}}/>{files.length>0 && <div className="my-2 flex flex-wrap gap-2">{files.map(file=><span key={file.id} className="inline-flex max-w-full items-center gap-1 rounded-md border border-border px-2 py-1 text-xs"><span className="truncate">{file.name}</span><button type="button" disabled={!!pending} aria-label={`Убрать ${file.name}`} onClick={()=>setFiles(files.filter(f=>f.id!==file.id))}><X size={12}/></button></span>)}</div>}<div className="mt-2 flex flex-wrap items-center justify-between gap-1"><div className="flex items-center"><input ref={fileInput} type="file" multiple accept=".jpg,.jpeg,.png,.webp,.pdf" aria-label="Прикрепить файлы" className="sr-only" disabled={uploading||sending||!!pending} onChange={e=>void upload(e.target.files)}/><Button type="button" variant="ghost" size="icon" aria-label="Выбрать вложения" disabled={uploading||sending||!!pending} onClick={()=>fileInput.current?.click()}>{uploading ? <LoaderCircle size={18} className="animate-spin"/> : <Paperclip size={18}/>}</Button>{!pending && <QuickReplies canManage={canManage} onChoose={text=>{const next=body ? `${body}\n${text}` : text;if(next.length>10000)setError('Сообщение не должно превышать 10000 символов.');else setBody(next);}}/>}</div><Button type="submit" disabled={sending||uploading||(!body.trim()&&!files.length)}>{sending ? <LoaderCircle size={16} className="animate-spin"/> : <Send size={16}/>}<span>{pending ? 'Повторить отправку' : kind==='note' ? 'Добавить заметку' : 'Отправить'}</span></Button></div>{pending && !sending && <button type="button" className="mt-2 text-xs text-muted-foreground underline" onClick={()=>setPending(null)}>Вернуться к редактированию черновика</button>}<p className="mt-2 hidden text-[10px] text-muted-foreground sm:block">Ctrl + Enter — отправить · JPG, PNG, WebP, PDF до 10 МБ</p></form> : <div className="border-t border-border bg-card p-5 text-center text-sm text-muted-foreground">{conversation?.status==='closed' ? 'Диалог завершён. Верните его в очередь, чтобы продолжить.' : 'Доступен просмотр переписки'}</div>}
    </section>;
}
