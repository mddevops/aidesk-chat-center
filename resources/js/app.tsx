import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
const pages = import.meta.glob('./pages/**/*.tsx');
createInertiaApp({
    title: title => title ? `${title} — AIDesk` : 'AIDesk',
    resolve: async name => {
        const page = pages[`./pages/${name}.tsx`];
        if (!page) throw new Error(`Страница ${name} не найдена`);
        return page();
    },
    setup({ el, App, props }) { createRoot(el).render(<App {...props} />); },
    progress: { color: '#7c5cfc' },
});
