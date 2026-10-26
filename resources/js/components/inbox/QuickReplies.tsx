import { useState } from 'react';
import { api } from '@/lib/api';
import { Button } from '@/components/ui/button';
import { inputClass } from '@/components/Field';
import type { QuickReply } from './types';

export function QuickReplies({onChoose, canManage}: {onChoose: (body: string) => void; canManage: boolean}) {
    const [open, setOpen] = useState(false);
    const [rows, setRows] = useState<QuickReply[]>([]);
    const [editing, setEditing] = useState<QuickReply | null>(null);
    const [creating, setCreating] = useState(false);
    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    async function load() { try { setRows((await api<{data:QuickReply[]}>('/api/v1/quick-replies')).data); } catch (error) { setError((error as Error).message); } }
    return <div className="relative" onKeyDown={event=>{if(event.key==='Escape')setOpen(false);}}><Button type="button" variant="ghost" onClick={() => {setOpen(!open); if (!open) void load();}} aria-expanded={open}>Быстрые ответы</Button>{open && <div className="absolute bottom-12 left-0 z-20 max-h-96 w-[min(340px,80vw)] overflow-y-auto rounded-xl border border-border bg-card p-4 shadow-xl"><div className="mb-3 flex justify-between"><h3 className="text-sm font-semibold">Быстрые ответы</h3><button type="button" onClick={() => setOpen(false)} aria-label="Закрыть быстрые ответы">×</button></div>{error && <p role="alert" className="text-sm text-red-600">{error}</p>}{!rows.length && <p className="mb-3 text-sm text-muted-foreground">Сохранённых ответов пока нет.</p>}{rows.map(reply => <div key={reply.id} className="flex items-center gap-1 border-b border-border"><button type="button" className="min-w-0 flex-1 py-3 text-left text-sm hover:text-primary" onClick={() => {onChoose(reply.body); setOpen(false);}}>{reply.title}</button>{canManage && <button type="button" className="text-xs text-muted-foreground" aria-label={`Изменить ответ ${reply.title}`} onClick={() => {setEditing(reply); setTitle(reply.title); setBody(reply.body); setCreating(true);}}>Изменить</button>}</div>)}{canManage && <Button type="button" variant="ghost" className="mt-2" onClick={() => {setEditing(null); setTitle(''); setBody(''); setCreating(!creating);}}>Новый быстрый ответ</Button>}{creating && <div className="mt-3 space-y-2"><label className="block text-xs">Название ответа<input className={inputClass} value={title} maxLength={100} onChange={e => setTitle(e.target.value)} /></label><label className="block text-xs">Текст быстрого ответа<textarea className={inputClass} value={body} maxLength={10000} onChange={e => setBody(e.target.value)} /></label><Button type="button" disabled={busy || !title.trim() || !body.trim()} onClick={async () => {setBusy(true); setError(''); try {await api(`/api/v1/quick-replies${editing ? `/${editing.id}` : ''}`, {method: editing ? 'PATCH' : 'POST', body:{title, body}}); setCreating(false); await load();} catch(error) {setError((error as Error).message);} finally {setBusy(false);}}}>Сохранить ответ</Button></div>}</div>}</div>;
}
