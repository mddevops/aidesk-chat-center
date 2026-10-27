import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Widget-only bearer authorizer: no cookie, query token or shared operator Echo singleton.
export function visitorEcho(endpoint:string, credential:string, onUnauthorized:()=>void) {
    const client = new Pusher(import.meta.env.VITE_REVERB_APP_KEY, {
        wsHost:import.meta.env.VITE_REVERB_HOST,
        wsPort:Number(import.meta.env.VITE_REVERB_PORT||80),wssPort:Number(import.meta.env.VITE_REVERB_PORT||443),
        forceTLS:import.meta.env.VITE_REVERB_SCHEME==='https',enabledTransports:['ws','wss'],cluster:'',
        channelAuthorization:{customHandler:async(params,callback)=>{
            try {
                const response=await fetch(endpoint+'/broadcasting/auth',{method:'POST',credentials:'omit',signal:AbortSignal.timeout(10000),headers:{Accept:'application/json','Content-Type':'application/json',Authorization:'Bearer '+credential},body:JSON.stringify({socket_id:params.socketId,channel_name:params.channelName})});
                if(response.status===401)onUnauthorized();
                if(!response.ok)throw new Error('Не удалось подписаться на обновления.');
                callback(null,await response.json());
            }catch(error){callback(error instanceof Error?error:new Error('Ошибка подключения.'),null);}
        }},
    });
    return new Echo({broadcaster:'reverb',client});
}
