import { useId, type InputHTMLAttributes, type ReactNode } from 'react';
export const inputClass = 'mt-1 w-full rounded-lg border border-border bg-background px-3 py-2.5 text-sm outline-none focus:border-primary focus:ring-1 focus:ring-primary';
export function Field({label, error, ...props}: InputHTMLAttributes<HTMLInputElement> & {label: string; error?: string}) {
    const id = useId();
    return <label htmlFor={id} className="block text-sm font-medium">{label}<input id={id} aria-invalid={!!error} aria-describedby={error ? `${id}-error` : undefined} className={inputClass} {...props} />{error && <span id={`${id}-error`} className="mt-1 block text-xs text-red-600 dark:text-red-400">{error}</span>}</label>;
}
export function FormErrors({errors}: {errors: Record<string, string | undefined>}) {
    const messages = Object.values(errors).filter(Boolean);
    return messages.length ? <div role="alert" className="rounded-lg border border-red-500/30 bg-red-500/5 p-3 text-sm text-red-600 dark:text-red-400">{messages.map((m, i) => <p key={i}>{m}</p>)}</div> : null;
}
export function Panel({title, children}: {title: string; children: ReactNode}) { return <section className="rounded-2xl border border-border bg-card p-5 sm:p-6"><h2 className="mb-5 text-lg font-semibold">{title}</h2>{children}</section>; }
