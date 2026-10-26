import { useState } from 'react';
import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
export function ThemeToggle() {
    const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'));
    function toggleTheme() {
        const next = !dark;
        document.documentElement.classList.toggle('dark', next);
        try { localStorage.setItem('aidesk-theme', next ? 'dark' : 'light'); } catch { /* Storage may be unavailable. */ }
        setDark(next);
    }
    return <Button variant="outline" size="icon" onClick={toggleTheme} aria-label={dark ? 'Включить светлую тему' : 'Включить тёмную тему'}>{dark ? <Sun size={18} /> : <Moon size={18} />}</Button>;
}
