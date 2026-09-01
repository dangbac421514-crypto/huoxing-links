<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>添加微信</title>
    <style>
        * { box-sizing: border-box; }
        html, body { min-height: 100%; margin: 0; }
        body {
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 28px 18px;
            color: #18212f;
            background: linear-gradient(180deg, #f4fff8 0%, #f7f8fb 55%, #eef2f6 100%);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
        }
        .card {
            width: min(100%, 440px);
            padding: 30px 24px 24px;
            border: 1px solid rgba(24, 33, 47, .08);
            border-radius: 24px;
            background: rgba(255, 255, 255, .96);
            box-shadow: 0 22px 60px rgba(31, 45, 61, .12);
            text-align: center;
        }
        .profile {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            min-height: 52px;
        }
        #avatar {
            display: none;
            width: 52px;
            height: 52px;
            border-radius: 14px;
            object-fit: cover;
        }
        h1 { margin: 0; font-size: 24px; line-height: 1.3; }
        #subtitle { margin: 8px 0 22px; color: #7b8492; font-size: 15px; }
        .qr-wrap {
            width: 100%;
            aspect-ratio: 1;
            display: grid;
            place-items: center;
            overflow: hidden;
            border: 1px solid #edf0f3;
            border-radius: 18px;
            background: #fff;
        }
        #qr {
            display: none;
            width: 100%;
            height: 100%;
            object-fit: contain;
            -webkit-touch-callout: default;
            user-select: auto;
        }
        #status { padding: 40px 12px; color: #8b94a2; }
        .tip { margin: 18px 0 0; color: #07c160; font-size: 16px; font-weight: 600; }
        .hint { margin: 8px 0 0; color: #a0a7b2; font-size: 13px; }
        .error { color: #d93025 !important; }
    </style>
</head>
<body>
<main class="card">
    <div class="profile">
        <img id="avatar" alt="微信头像">
        <h1 id="title">添加微信</h1>
    </div>
    <p id="subtitle">长按识别二维码</p>
    <div class="qr-wrap">
        <div id="status">正在加载二维码…</div>
        <img id="qr" alt="微信二维码">
    </div>
    <p class="tip">长按二维码添加好友</p>
    <p class="hint">如无法识别，可保存图片后打开微信扫一扫</p>
</main>
<script>
    (function () {
        'use strict';

        var code = @json($code);
        var params = new URLSearchParams(window.location.search);
        var keys = Array.from(params.keys());
        var tokens = params.getAll('visitor_token');
        var status = document.getElementById('status');
        var qr = document.getElementById('qr');
        var avatar = document.getElementById('avatar');

        function fail(message) {
            status.textContent = message || '二维码暂不可用';
            status.classList.add('error');
            status.style.display = 'block';
            qr.style.display = 'none';
        }

        function storageAsset(value) {
            if (typeof value !== 'string' || value === '') return null;
            var url = new URL(value, window.location.origin);
            if (url.origin !== window.location.origin || !url.pathname.startsWith('/storage/')) return null;
            return url.href;
        }

        if (keys.length !== 1 || keys[0] !== 'visitor_token' || tokens.length !== 1 || !tokens[0]) {
            fail('访问凭证无效或已过期');
            return;
        }

        var token = tokens[0];
        window.history.replaceState(null, '', window.location.pathname);

        fetch('/api/link-show-qr/' + encodeURIComponent(code) + '?visitor_token=' + encodeURIComponent(token), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                var data = payload && payload.code === 0 ? payload.data : null;
                var qrUrl = data ? storageAsset(data.qr) : null;
                if (!data || !qrUrl) {
                    fail((payload && payload.message) || '二维码暂不可用');
                    return;
                }

                document.getElementById('title').textContent = data.title || '添加微信';
                document.getElementById('subtitle').textContent = data.sub_title || '长按识别二维码';
                document.title = data.title || '添加微信';

                var avatarUrl = storageAsset(data.avatar);
                if (avatarUrl) {
                    avatar.src = avatarUrl;
                    avatar.style.display = 'block';
                }

                qr.onload = function () {
                    status.style.display = 'none';
                    qr.style.display = 'block';
                };
                qr.onerror = function () { fail('二维码图片加载失败'); };
                qr.src = qrUrl;
            })
            .catch(function () { fail('二维码加载失败，请稍后重试'); });
    }());
</script>
</body>
</html>
