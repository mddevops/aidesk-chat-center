import { Head, Link, useForm } from '@inertiajs/react';
import { MessageSquare } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Field, FormErrors } from '@/components/Field';
import { ThemeToggle } from '@/components/ThemeToggle';

export default function Login() {
    const form = useForm({email: '', password: ''});
    return <><Head title="Вход в кабинет" /><div className="min-h-dvh p-5"><div className="flex justify-end"><ThemeToggle /></div><main className="mx-auto mt-12 max-w-md rounded-2xl border border-border bg-card p-7 sm:mt-24"><Link href="/" className="inline-flex items-center gap-2 text-xl font-semibold"><MessageSquare className="text-primary" />AIDesk</Link><h1 className="mt-8 text-2xl font-semibold">Вход в кабинет</h1><p className="mt-2 text-sm text-muted-foreground">Рабочее пространство вашей команды</p><form className="mt-7 space-y-5" onSubmit={e => {e.preventDefault(); form.post('/login', {onFinish: () => form.reset('password')});}}><FormErrors errors={form.errors} /><Field label="Электронная почта" type="email" autoComplete="username" required value={form.data.email} onChange={e => form.setData('email', e.target.value)} /><Field label="Пароль" type="password" autoComplete="current-password" required value={form.data.password} onChange={e => form.setData('password', e.target.value)} /><Button disabled={form.processing} className="w-full">{form.processing ? 'Входим…' : 'Войти'}</Button></form><p className="mt-6 text-xs leading-5 text-muted-foreground">Доступ предоставляет администратор вашей организации.</p></main></div></>;
}
