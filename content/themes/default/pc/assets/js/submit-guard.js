/**
 * 前台通用防重复提交守卫（fetch 层）
 *
 * - 对前台写操作端点的同源 POST 请求自动附加 X-Todo-Submit-Key 头（服务端 PRG 幂等键）
 * - 相同 URL + 相同请求体的请求在途时，复用同一个 Promise，避免连点/重复触发
 * - 已自行携带 _submit_key / X-Todo-Submit-Key 的请求不会被覆盖
 */
(function () {
    'use strict';
    if (!window.fetch || window.__todoSubmitGuard) { return; }
    window.__todoSubmitGuard = true;

    var GUARDED = /\/(includes\/ajax\/(cart|checkout|payment|create-payment|cancel-order|confirm-receive|order|address|coupon|review|favorites)[a-z-]*\.php|api\/(user|sms|guestbook)\/[a-z-]+\.php|admin\/admin-ajax\.php)/i;
    var inflight = {};

    function uuid() {
        var h = function () { return Math.random().toString(16).slice(2); };
        return Date.now().toString(16) + '-' + h() + h();
    }

    function fingerprint(url, body) {
        if (body == null) { return url; }
        if (typeof body === 'string') { return url + '|' + body; }
        if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) { return url + '|' + body.toString(); }
        if (typeof FormData !== 'undefined' && body instanceof FormData) {
            var parts = [];
            var skip = false;
            body.forEach(function (v, k) {
                if (k === '_submit_key') { return; }
                if (typeof v !== 'string') { skip = true; return; }
                parts.push(k + '=' + v);
            });
            return skip ? null : url + '|' + parts.join('&');
        }
        return null;
    }

    var nativeFetch = window.fetch.bind(window);

    window.fetch = function (input, init) {
        try {
            init = init || {};
            var method = String(init.method || (input && input.method) || 'GET').toUpperCase();
            var url = typeof input === 'string' ? input : (input && input.url) || '';
            if (method !== 'POST' || !GUARDED.test(url)) { return nativeFetch(input, init); }

            var key = fingerprint(url, init.body);
            if (key && inflight[key]) {
                return inflight[key].then(function (r) { return r.clone(); });
            }

            var headers = new Headers(init.headers || (input && input.headers) || {});
            var hasKey = headers.has('X-Todo-Submit-Key') ||
                (typeof FormData !== 'undefined' && init.body instanceof FormData && init.body.has('_submit_key'));
            if (!hasKey) { headers.set('X-Todo-Submit-Key', uuid()); }
            init = Object.assign({}, init, { headers: headers });

            var p = nativeFetch(input, init);
            if (key) {
                inflight[key] = p;
                var done = function () { delete inflight[key]; };
                p.then(done, done);
                return p.then(function (r) { return r.clone(); });
            }
            return p;
        } catch (e) {
            return nativeFetch(input, init);
        }
    };
})();
