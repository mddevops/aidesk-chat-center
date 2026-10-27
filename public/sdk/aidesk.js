(function () {
    'use strict';
    var script = document.currentScript;
    if (!script || !script.dataset.channel) return;
    var channel = script.dataset.channel;
    if (!/^[a-f0-9-]{36}$/i.test(channel) || document.querySelector('[data-aidesk-host="' + channel + '"]')) return;
    var base = new URL(script.src).origin;
    var endpoint = base + '/widget/v1/channels/' + channel;
    fetch(endpoint + '/configuration', {credentials: 'omit', headers: {Accept: 'application/json'}}).then(function (r) {
        if (!r.ok) throw new Error('configuration');
        return r.json();
    }).then(function (result) {
        var c = result.data.configuration;
        var host = document.createElement('div');
        host.dataset.aideskHost = channel;
        document.body.appendChild(host);
        var root = host.attachShadow({mode: 'open'});
        var style = document.createElement('style');
        style.textContent = ':host{all:initial!important;position:fixed!important;z-index:2147483000!important;bottom:' + c.offset_y + 'px!important;' + c.position + ':' + c.offset_x + 'px!important}button{all:initial;box-sizing:border-box;cursor:pointer;background:' + c.color + ';color:white;font:600 15px system-ui;padding:17px 22px;border-radius:28px;box-shadow:0 4px 24px #0003}iframe{display:none;border:0;background:transparent;width:min(' + c.width + 'px,calc(100vw - 24px));height:min(' + c.height + 'px,calc(100dvh - 100px));border-radius:' + c.radius + 'px;' + (c.shadow ? 'box-shadow:0 12px 60px #0004;' : '') + 'margin-bottom:12px}' + (c.mobile_fullscreen ? '@media(max-width:600px){iframe{position:fixed;inset:0;width:100vw;height:100dvh;border-radius:0}}' : '');
        root.appendChild(style);
        var frame = document.createElement('iframe');
        frame.title = c.name;
        frame.referrerPolicy = 'no-referrer';
        frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-forms allow-downloads allow-popups');
        var button = document.createElement('button');
        button.type = 'button'; button.textContent = 'Написать нам'; button.setAttribute('aria-expanded', 'false');
        root.appendChild(frame); root.appendChild(button);
        var ready = false, loaded = false, credential = null, bootstrap = null;
        var key = 'aidesk-session:' + base + ':' + channel;
        try { credential = localStorage.getItem(key); } catch (_) {}
        function post(data) { if (!ready) return; frame.contentWindow.postMessage(data, base); }
        button.addEventListener('click', function () {
            var open = frame.style.display !== 'block';
            frame.style.display = open ? 'block' : 'none'; button.setAttribute('aria-expanded', String(open));
            if (open && !loaded) { frame.src = endpoint + '/frame'; loaded = true; }
            post({type:'aidesk:visible', visible:open});
        });
        window.addEventListener('message', function (event) {
            if (event.origin !== base || event.source !== frame.contentWindow || !event.data) return;
            if (event.data.type === 'aidesk:close') { frame.style.display = 'none'; button.setAttribute('aria-expanded', 'false'); post({type:'aidesk:visible',visible:false}); }
            if (event.data.type === 'aidesk:ready') { ready=true; post({type:'aidesk:credential', credential:credential}); post({type:'aidesk:visible',visible:frame.style.display==='block'}); }
            if (event.data.type === 'aidesk:forget') { credential = null; try { localStorage.removeItem(key); } catch (_) {} }
            if (event.data.type === 'aidesk:start') {
                if (credential) { post({type:'aidesk:credential', credential:credential}); return; }
                if (bootstrap) return;
                bootstrap = fetch(endpoint + '/sessions', {method:'POST',credentials:'omit',signal:AbortSignal.timeout(15000),headers:{Accept:'application/json','Content-Type':'application/json'},body:'{}'}).then(function(r){if(!r.ok)throw new Error('session');return r.json();}).then(function(result){credential=result.data.credential;try{localStorage.setItem(key,credential);}catch(_){}post({type:'aidesk:credential',credential:credential});}).catch(function(){post({type:'aidesk:error'});}).finally(function(){bootstrap=null;});
            }
        });
    }).catch(function () { /* Unpublished or disallowed sites must not mount a chat. */ });
})();
