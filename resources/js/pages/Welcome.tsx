import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { MessageSquare, Moon, Sun, ShieldCheck, Layers3, ArrowUpRight } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

export default function Welcome() {
    const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));
    function toggleTheme() {
        const next = !dark;
        document.documentElement.classList.toggle('dark', next);
        try { localStorage.setItem('aidesk-theme', next ? 'dark' : 'light'); } catch { /* Storage may be unavailable. */ }
        setDark(next);
    }
    return <>
        <Head title="Центр общения" />
        <div className="min-h-dvh">
            <header className="flex items-center justify-between border-b border-border bg-card px-5 py-4 sm:px-10">
                <a href="/" className="flex items-center gap-3 font-semibold text-xl"><span className="rounded-xl bg-primary p-2 text-white"><MessageSquare size={22} /></span>AIDesk <span className="hidden text-sm font-normal text-muted-foreground sm:inline">Центр общения</span></a>
                <Button variant="outline" size="icon" onClick={toggleTheme} aria-label={dark ? 'Включить светлую тему' : 'Включить тёмную тему'}>{dark ? <Sun size={18} /> : <Moon size={18} />}</Button>
            </header>
            <main className="mx-auto max-w-5xl px-5 py-16 sm:py-24">
                <Badge variant="primary-light" size="xl" radius="full">Платформа общения с клиентами автосалонов</Badge>
                <h1 className="mt-7 max-w-3xl text-4xl font-semibold tracking-tight sm:text-6xl">Каждый разговор —<br /><span className="text-primary">начало отношений.</span></h1>
                <p className="mt-6 max-w-xl text-lg leading-8 text-muted-foreground">Единое пространство для команды, диалогов и обращений с сайтов ваших автосалонов.</p>
                <div className="mt-12 grid gap-5 sm:grid-cols-3">
                    {[{ icon: MessageSquare, title: 'Диалоги', text: 'Виджет сайта и рабочее место оператора.' }, { icon: ShieldCheck, title: 'Безопасный доступ', text: 'Разделение организаций и серверная проверка прав.' }, { icon: Layers3, title: 'Ваши сайты', text: 'Независимые настройки виджетов для каждого сайта.' }].map(({ icon: Icon, title, text }) => <article key={title} className="rounded-2xl border border-border bg-card p-6"><Icon className="mb-5 text-primary" size={24} /><h2 className="font-semibold">{title}</h2><p className="mt-2 text-sm leading-6 text-muted-foreground">{text}</p></article>)}
                </div>
                <div className="mt-8 flex items-start gap-3 rounded-xl border border-border p-5 text-sm text-muted-foreground"><ArrowUpRight className="shrink-0" size={20} /><p>Платформа в разработке. Вход операторов и обмен сообщениями будут доступны после подключения соответствующих модулей.</p></div>
            </main>
            <footer className="px-5 pb-8 text-center text-xs text-muted-foreground">AIDesk · Создано для внимательного сервиса</footer>
        </div>
    </>;
}
