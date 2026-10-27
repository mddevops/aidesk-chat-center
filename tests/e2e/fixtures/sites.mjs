import http from 'node:http';
for (const port of [8091, 8092, 8093, 8094, 8095, 8096, 8097, 8098]) {
    http.createServer((request, response) => {
        const channel = new URL(request.url, 'http://localhost').searchParams.get('channel');
        const sdk = /^[a-f0-9-]{36}$/i.test(channel || '') ? `<script src="http://127.0.0.1:8000/sdk/aidesk.js" data-channel="${channel}" defer></script>` : '';
        response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
        response.end(`<!doctype html><html lang="ru"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Тестовый автосалон ${port}</title><body><h1>Тестовый сайт автосалона ${port}</h1>${sdk}</body></html>`);
    }).listen(port, '127.0.0.1');
}
