import {useEffect,useRef,useState} from 'react';
import {api,ApiError} from '@/lib/api';
type State={status:string;revision:number;capacity:number};
export function Availability(){
    const [state,setState]=useState<State|null>(null),[error,setError]=useState(''),[busy,setBusy]=useState(false);
    const current=useRef<State|null>(null);
    function accept(next:State){if(!current.current||next.revision>=current.current.revision){current.current=next;setState(next);}}
    async function load(){try{accept((await api<{data:State}>('/api/v1/availability')).data);}catch{setError('Нет статуса');}}
    async function heartbeat(status:string){const value=current.current;if(!value)return;try{accept((await api<{data:State}>('/api/v1/availability',{method:'POST',body:{status,revision:value.revision}})).data);setError('');}catch(e){setError((e as Error).message);if(e instanceof ApiError&&e.status===409)await load();}}
    useEffect(()=>{void load();const interval=setInterval(()=>{if(current.current?.status==='available'&&document.visibilityState==='visible')void heartbeat('available');},30000);const restore=()=>void load();window.addEventListener('focus',restore);return()=>{clearInterval(interval);window.removeEventListener('focus',restore);};},[]);
    return <label className="min-w-0 text-xs"><span className="sr-only">Доступность оператора</span><select aria-label="Доступность оператора" disabled={!state||busy} className="max-w-[135px] rounded-lg border border-border bg-card px-2 py-2 text-xs" value={state?.status||'offline'} onChange={async e=>{setBusy(true);await heartbeat(e.target.value);setBusy(false);}}><option value="offline">Не в сети</option><option value="available">Принимаю чаты</option><option value="away">Отошёл</option></select>{error&&<span role="alert" className="block max-w-36 text-[10px] text-red-600">{error}</span>}</label>;
}
