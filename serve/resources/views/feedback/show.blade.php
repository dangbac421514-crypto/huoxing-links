<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $channel->name }} · 商家售后反馈</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 16px;
            line-height: 1.5;
            color: #1f2937;
            background: #f3f4f6;
        }
        main {
            max-width: 480px;
            margin: 0 auto;
            padding: 16px 16px 32px;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
        }
        .badge {
            display: inline-block;
            font-size: 12px;
            color: #1d4ed8;
            background: #eff6ff;
            border-radius: 999px;
            padding: 2px 8px;
        }
        h1 { font-size: 20px; margin: 8px 0; }
        p { margin: 8px 0; }
        .notice {
            background: #fff7ed;
            color: #9a3412;
            border-radius: 8px;
            padding: 12px;
            font-size: 14px;
        }
        label, .label { display: block; font-weight: 600; margin: 12px 0 6px; }
        select, textarea, input[type="text"], input[type="file"] {
            width: 100%;
            font-size: 16px;
            padding: 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #fff;
        }
        textarea { min-height: 120px; resize: vertical; }
        .hint { font-size: 13px; color: #6b7280; font-weight: 400; }
        .consent { display: flex; gap: 8px; align-items: flex-start; font-weight: 400; }
        .consent input { width: auto; margin-top: 4px; }
        button[type="submit"] {
            width: 100%;
            min-height: 44px;
            margin-top: 16px;
            border: 0;
            border-radius: 8px;
            background: #2563eb;
            color: #fff;
            font-size: 16px;
        }
    </style>
</head>
<body>
    <main>
        <section class="card">
            <span class="badge">商家售后反馈</span>
            <h1>{{ $channel->name }}</h1>
            <p>运营主体：{{ $channel->operator_name }}</p>
            @if (filled($channel->intro))
                <p>{{ $channel->intro }}</p>
            @endif
            <p>处理时效：{{ $channel->sla_text }}</p>
            @if (filled($channel->service_phone))
                <p>客服电话：{{ $channel->service_phone }}</p>
            @endif
            <p class="notice">本页面由上述商家运营，并非企业微信官方投诉入口</p>
        </section>
        <section class="card">
            <form id="feedback-form" method="post" action="/f/{{ $channel->code }}/tickets" enctype="multipart/form-data">
                @csrf
                <label for="category">问题分类</label>
                <select id="category" name="category" required>
                    @foreach ((array) $channel->categories as $category)
                        <option value="{{ $category }}">{{ $category }}</option>
                    @endforeach
                </select>

                <label for="content">问题说明 <span class="hint">10–2000 字</span></label>
                <textarea id="content" name="content" required minlength="10" maxlength="2000" rows="6"></textarea>

                <label for="contact">联系方式 <span class="hint">{{ $channel->contact_required ? '必填' : '选填' }}</span></label>
                <input id="contact" name="contact" type="text" maxlength="80" @if ($channel->contact_required) required @endif>

                <p class="label">图片凭证 <span class="hint">最多 3 张，JPEG/PNG/WebP，每张不超过 5 MiB</span></p>
                <input type="file" name="attachments[]" accept="image/jpeg,image/png,image/webp">
                <input type="file" name="attachments[]" accept="image/jpeg,image/png,image/webp">
                <input type="file" name="attachments[]" accept="image/jpeg,image/png,image/webp">

                <p>提交即表示您同意由 {{ $channel->operator_name }} 处理您的售后问题。我们将收集问题分类、问题说明、联系方式及图片凭证，保存 {{ $channel->retention_days }} 天。如需查阅、更正或删除所提交信息，请通过本页客服电话联系上述商家。</p>
                <label class="consent">
                    <input type="checkbox" name="privacy_accepted" value="1" required>
                    <span>我已阅读并同意上述说明</span>
                </label>

                <button type="submit">提交反馈</button>
            </form>
        </section>
    </main>
    <script>
        (function () {
            var form = document.getElementById('feedback-form');
            if (!form || !window.crypto || typeof window.crypto.randomUUID !== 'function') {
                return;
            }
            var button = form.querySelector('button[type="submit"]');
            var idempotencyKey = window.crypto.randomUUID();
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!button || button.disabled) {
                    return;
                }
                button.disabled = true;
                var data = new FormData(form);
                data.delete('attachments[]');
                var fileInputs = form.querySelectorAll('input[type="file"]');
                var attached = 0;
                for (var i = 0; i < fileInputs.length; i++) {
                    if (attached >= 3) {
                        break;
                    }
                    if (fileInputs[i].files && fileInputs[i].files.length) {
                        data.append('attachments[]', fileInputs[i].files[0]);
                        attached++;
                    }
                }
                data.set('idempotency_key', idempotencyKey);
                fetch(form.getAttribute('action'), {
                    method: 'POST',
                    body: data,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function (response) {
                    if (response.status === 200 || response.status === 201) {
                        return response.json().then(function (body) {
                            idempotencyKey = window.crypto.randomUUID();
                            var result = document.createElement('div');
                            var heading = document.createElement('p');
                            heading.textContent = '提交成功';
                            var number = document.createElement('p');
                            number.textContent = '工单号：' + (body.public_no || '');
                            result.appendChild(heading);
                            result.appendChild(number);
                            form.replaceWith(result);
                        });
                    }
                    button.disabled = false;
                }).catch(function () {
                    button.disabled = false;
                });
            });
        })();
    </script>
</body>
</html>
