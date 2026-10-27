<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Чат автосалона</title>
    @viteReactRefresh
    @vite('resources/js/widget.tsx')
</head>
<body style="margin:0"><div id="widget-root"></div><script type="application/json" id="widget-config">{!! json_encode(['configuration' => $configuration, 'channel' => $channel, 'parentOrigin' => $parentOrigin], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script></body>
</html>
