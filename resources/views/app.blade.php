<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">try{if(localStorage.getItem('aidesk-theme')==='dark'||(!localStorage.getItem('aidesk-theme')&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark')}catch{}</script>
    @viteReactRefresh
    @vite('resources/js/app.tsx')
    @inertiaHead
</head>
<body class="font-sans antialiased">@inertia</body>
</html>
