import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [laravel({ input: ['resources/js/app.tsx', 'resources/js/widget.tsx'], refresh: true }), react(), tailwindcss()],
    resolve: { alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) } },
});
