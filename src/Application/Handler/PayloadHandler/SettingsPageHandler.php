<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Handler\PayloadHandler;

use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Contract\HandlerInterface;
use Semitexa\Core\Contract\PayloadInterface;
use Semitexa\Core\Contract\ResourceInterface;
use Semitexa\Core\Response;
use Semitexa\Platform\Settings\Application\Payload\Request\SettingsPagePayload;

#[AsPayloadHandler(
    payload: SettingsPagePayload::class,
    resource: \Semitexa\Core\Http\Response\GenericResponse::class,
)]
final class SettingsPageHandler implements HandlerInterface
{
    public function handle(PayloadInterface $payload, ResourceInterface $resource): ResourceInterface
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings — Semitexa Platform</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; background: #1e1e2e; color: #cdd6f4; padding: 24px; }
        h1 { font-size: 20px; margin-bottom: 8px; }
        p { color: #a6adc8; font-size: 14px; margin-bottom: 24px; }
        .section { background: #313244; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .section h2 { font-size: 14px; margin: 0 0 12px; color: #89b4fa; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #45475a; }
        th { color: #a6adc8; font-weight: 500; }
        .value { font-family: ui-monospace, monospace; font-size: 12px; max-width: 300px; overflow: hidden; text-overflow: ellipsis; }
        .empty { color: #6c7086; font-style: italic; }
        .add { margin-top: 12px; }
        .add input, .add button { padding: 8px 12px; border-radius: 6px; border: 1px solid #45475a; background: #1e1e2e; color: #cdd6f4; font-size: 13px; margin-right: 8px; }
        .add button { background: #89b4fa; color: #1e1e2e; border: none; cursor: pointer; }
        .add button:hover { background: #b4befe; }
    </style>
</head>
<body>
    <h1>⚙ System Settings</h1>
    <p>Key-value store per module. Multi-tenant aware. Use <code>SettingsStoreInterface</code> in your module to read/write.</p>
    <div id="app"></div>
    <script>
    (function() {
        var api = '/api/platform/settings';
        function load() {
            fetch(api, { credentials: 'include' }).then(function(r) { return r.json(); }).then(function(data) {
                var byModule = {};
                (data.settings || []).forEach(function(s) {
                    if (!byModule[s.module_key]) byModule[s.module_key] = [];
                    byModule[s.module_key].push(s);
                });
                var html = '';
                Object.keys(byModule).sort().forEach(function(mod) {
                    html += '<div class="section"><h2>' + escapeHtml(mod) + '</h2><table><tr><th>Key</th><th>Value</th></tr>';
                    byModule[mod].forEach(function(s) {
                        var v = typeof s.value === 'string' ? s.value : JSON.stringify(s.value);
                        html += '<tr><td>' + escapeHtml(s.key) + '</td><td class="value" title="' + escapeHtml(v) + '">' + escapeHtml(v) + '</td></tr>';
                    });
                    html += '</table></div>';
                });
                if (Object.keys(byModule).length === 0) html = '<div class="section"><p class="empty">No settings yet. Modules can store settings via SettingsStoreInterface.</p></div>';
                document.getElementById('app').innerHTML = html;
            }).catch(function() { document.getElementById('app').innerHTML = '<div class="section"><p class="empty">Failed to load settings.</p></div>'; });
        }
        function escapeHtml(s) {
            if (!s) return '';
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }
        load();
    })();
    </script>
</body>
</html>
HTML;
        return Response::html($html);
    }
}
