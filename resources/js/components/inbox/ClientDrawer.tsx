import { useEffect, useRef, type ReactNode } from 'react';

export function ClientDrawer({children, onClose}: {children:ReactNode; onClose:()=>void}) {
    const dialog = useRef<HTMLDialogElement>(null);
    useEffect(()=>{const element=dialog.current;element?.showModal();return()=>element?.close();},[]);
    return <dialog ref={dialog} aria-label="Данные клиента" className="fixed inset-y-0 left-auto right-0 m-0 h-dvh max-h-none w-[min(360px,94vw)] max-w-none border-0 border-l border-border bg-card p-0 text-foreground shadow-2xl backdrop:bg-black/30" onCancel={event=>{event.preventDefault();onClose();}} onClick={event=>{if(event.target===event.currentTarget){const box=event.currentTarget.getBoundingClientRect();if(event.clientX<box.left)onClose();}}}>{children}</dialog>;
}
