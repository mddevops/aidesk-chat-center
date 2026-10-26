import http from 'node:http';
for (const port of [8091, 8092, 8093, 8094]) {
    http.createServer((request, response) => {
        response.writeHead(200, {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'});
        response.end(`<!doctype html><html lang="ru"><title>Тестовый автосалон ${port}</title><body><h1>Тестовый сайт автосалона ${port}</h1></body></html>`);
    }).listen(port, '127.0.0.1');
}
