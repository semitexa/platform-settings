<?php

declare(strict_types=1);

namespace Semitexa\Platform\Settings\Application\Handler\PayloadHandler;

use Psr\Container\ContainerInterface;
use Semitexa\Core\Attributes\AsPayloadHandler;
use Semitexa\Core\Attributes\InjectAsReadonly;
use Semitexa\Core\Auth\AuthContextInterface;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\Response\GenericResponse;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Platform\Settings\Application\Payload\Request\SettingsPagePayload;

#[AsPayloadHandler(
    payload: SettingsPagePayload::class,
    resource: GenericResponse::class,
)]
final class SettingsPageHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected AuthContextInterface $auth;

    #[InjectAsReadonly]
    protected ?ContainerInterface $container = null;

    public function handle(SettingsPagePayload $payload, GenericResponse $resource): GenericResponse
    {
        ModuleRegistry::initialize();
        $modules = [];
        foreach (ModuleRegistry::getModules() as $module) {
            $name = (string)($module['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $label = ucwords(str_replace(['-', '_'], ' ', $name));
            $modules[$name] = ['key' => $name, 'label' => $label];
        }
        if (!isset($modules['platform-wm'])) {
            $modules['platform-wm'] = ['key' => 'platform-wm', 'label' => 'Platform Wm'];
        }
        ksort($modules);
        $modulesJson = json_encode(array_values($modules), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $canManageGlobal = $this->canManageGlobalSettings();
        $canManageGlobalJson = $canManageGlobal ? 'true' : 'false';
        $globalOptionHtml = $canManageGlobal ? '<option value="global">Global</option>' : '';

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — Semitexa Platform</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; background: #0f172a; color: #dbe7ff; padding: 24px; }
        h1 { font-size: 20px; margin-bottom: 8px; }
        p { color: #a8b4cc; font-size: 14px; margin-bottom: 24px; }
        .section { background: #111d34; border: 1px solid #223250; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
        .section h2 { font-size: 14px; margin: 0 0 12px; color: #7dd3fc; }
        .toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
        .toolbar label { font-size: 13px; color: #a8b4cc; }
        .toolbar select { height: 34px; border-radius: 8px; border: 1px solid #2a3d62; background: #0b1628; color: #dbe7ff; padding: 0 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #223250; }
        th { color: #a8b4cc; font-weight: 500; }
        .value { font-family: ui-monospace, monospace; font-size: 12px; max-width: 300px; overflow: hidden; text-overflow: ellipsis; }
        .empty { color: #6c7086; font-style: italic; }
        .module-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 12px; }
        .module-box { border: 1px solid #223250; border-radius: 10px; padding: 12px; background: #0b1628; }
        .module-box h3 { margin: 0 0 10px; font-size: 14px; color: #dbe7ff; }
        .add { margin-top: 10px; display: grid; grid-template-columns: 1fr 1fr auto; gap: 8px; }
        .add input, .add button { padding: 8px 10px; border-radius: 8px; border: 1px solid #2a3d62; background: #0b1628; color: #dbe7ff; font-size: 13px; }
        .add button { background: #0ea5e9; color: #f8fbff; border: none; cursor: pointer; }
        .add button:hover { background: #38bdf8; }
        .preset-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; }
        .preset { border: 1px solid #2a3d62; border-radius: 10px; padding: 8px; background: #0b1628; cursor: pointer; color: #dbe7ff; text-align: left; }
        .preset:hover { border-color: #38bdf8; }
        .preview { height: 70px; border-radius: 8px; margin-bottom: 8px; border: 1px solid #2a3d62; }
        .hint { color: #8ea3c2; font-size: 12px; margin-top: 8px; }
        .scope-badge { font-size: 11px; color: #a8b4cc; padding: 4px 8px; border: 1px solid #2a3d62; border-radius: 999px; }
    </style>
</head>
<body>
    <h1>⚙ Settings</h1>
    <p>Settings are split automatically by modules. No manual module codes needed.</p>
    <div class="section">
        <div class="toolbar">
            <label for="scope">Scope</label>
            <select id="scope">
                <option value="user" selected>Personal</option>
                {$globalOptionHtml}
            </select>
            <span id="scopeBadge" class="scope-badge">Personal</span>
        </div>
        <div class="hint">Tip: for values you can use plain text or JSON.</div>
    </div>
    <div class="section">
        <h2>Window Manager Background</h2>
        <div id="wmPresets" class="preset-grid"></div>
    </div>
    <div id="app" class="module-grid"></div>
    <script>
    (function() {
        var api = '/api/platform/settings';
        var canManageGlobal = {$canManageGlobalJson};
        var moduleCatalog = $modulesJson;
        var presets = [
            { name: 'Default', value: '' },
            { name: 'Aurora', value: 'radial-gradient(900px 420px at 15% 10%, rgba(14,165,233,.20), transparent 60%), radial-gradient(700px 420px at 80% 0%, rgba(56,189,248,.12), transparent 55%), linear-gradient(160deg, #0f172a, #17233b)' },
            { name: 'Sunset', value: 'radial-gradient(900px 520px at 10% 10%, rgba(251,146,60,.22), transparent 60%), radial-gradient(700px 420px at 90% 10%, rgba(244,63,94,.14), transparent 55%), linear-gradient(160deg, #21132f, #3b1f4f)' },
            { name: 'Forest', value: 'radial-gradient(900px 520px at 15% 10%, rgba(74,222,128,.18), transparent 60%), radial-gradient(700px 420px at 85% 0%, rgba(20,184,166,.13), transparent 55%), linear-gradient(160deg, #0b1f1c, #12332d)' },
            { name: 'Slate', value: 'linear-gradient(180deg, #0f172a, #111827)' }
        ];

        function currentScope() {
            var scope = document.getElementById('scope').value || 'user';
            if (scope === 'global' && !canManageGlobal) return 'user';
            return scope;
        }
        function refreshScopeBadge() {
            var scope = currentScope();
            document.getElementById('scopeBadge').textContent = scope === 'user' ? 'Personal' : 'Global';
        }

        function apiGet(params) {
            var query = new URLSearchParams(params || {}).toString();
            return fetch(api + (query ? ('?' + query) : ''), { credentials: 'include' }).then(function(r) { return r.json(); });
        }

        function apiSet(body) {
            return fetch(api, {
                method: 'POST',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            }).then(function(r) { return r.json(); });
        }

        function load() {
            apiGet({ scope: currentScope() }).then(function(data) {
                var byModule = {};
                (data.settings || []).forEach(function(s) {
                    if (!byModule[s.module_key]) byModule[s.module_key] = [];
                    byModule[s.module_key].push(s);
                });

                var catalogMap = {};
                (moduleCatalog || []).forEach(function(m) { catalogMap[m.key] = m.label; });
                Object.keys(byModule).forEach(function(k) {
                    if (!catalogMap[k]) catalogMap[k] = k;
                });
                var moduleKeys = Object.keys(catalogMap).sort();
                var html = '';
                moduleKeys.forEach(function(mod) {
                    html += '<div class="module-box" data-module="' + escapeHtml(mod) + '">';
                    html += '<h3>' + escapeHtml(catalogMap[mod]) + ' <small style="color:#8ea3c2;">(' + escapeHtml(mod) + ')</small></h3>';
                    html += '<table><tr><th>Key</th><th>Value</th></tr>';
                    var rows = byModule[mod] || [];
                    if (rows.length === 0) {
                        html += '<tr><td colspan="2" class="empty">No settings for this module</td></tr>';
                    } else {
                        rows.forEach(function(s) {
                            var v = typeof s.value === 'string' ? s.value : JSON.stringify(s.value);
                            html += '<tr><td>' + escapeHtml(s.key) + '</td><td class="value" title="' + escapeHtml(v) + '">' + escapeHtml(v) + '</td></tr>';
                        });
                    }
                    html += '</table>';
                    html += '<div class="add">';
                    html += '<input data-role="key" placeholder="key (e.g. desktop_background)" />';
                    html += '<input data-role="value" placeholder="value (JSON or string)" />';
                    html += '<button data-role="save">Save</button>';
                    html += '</div></div>';
                });
                document.getElementById('app').innerHTML = html || '<div class="section"><p class="empty">No modules discovered.</p></div>';
                bindSaveButtons();
            }).catch(function() { document.getElementById('app').innerHTML = '<div class="section"><p class="empty">Failed to load settings.</p></div>'; });
        }

        function saveSetting(moduleKey, key, value) {
            return apiSet({
                scope: currentScope(),
                module_key: moduleKey,
                key: key,
                value: value
            }).then(function(res) {
                if (res.error) throw new Error(res.error);
                return res;
            });
        }

        function parseInputValue(raw) {
            var t = (raw || '').trim();
            if (t === '') return '';
            if ((t.startsWith('{') && t.endsWith('}')) || (t.startsWith('[') && t.endsWith(']')) || t === 'true' || t === 'false' || t === 'null' || /^-?\d+(\.\d+)?$/.test(t)) {
                try { return JSON.parse(t); } catch (e) { return t; }
            }
            return t;
        }

        function bindSaveButtons() {
            var boxes = document.querySelectorAll('.module-box');
            boxes.forEach(function(box) {
                var moduleKey = box.getAttribute('data-module');
                var keyEl = box.querySelector('input[data-role="key"]');
                var valueEl = box.querySelector('input[data-role="value"]');
                var saveBtn = box.querySelector('button[data-role="save"]');
                if (!moduleKey || !keyEl || !valueEl || !saveBtn) return;
                saveBtn.addEventListener('click', function() {
                    var key = (keyEl.value || '').trim();
                    if (!key) {
                        alert('Key is required');
                        return;
                    }
                    var value = parseInputValue(valueEl.value);
                    saveSetting(moduleKey, key, value).then(load).catch(function(e) { alert(e.message); });
                });
            });
        }

        function renderWmPresets() {
            var wrap = document.getElementById('wmPresets');
            wrap.innerHTML = '';
            presets.forEach(function(p) {
                var btn = document.createElement('button');
                btn.className = 'preset';
                btn.type = 'button';
                var preview = document.createElement('div');
                preview.className = 'preview';
                preview.style.background = p.value || 'linear-gradient(160deg, #0f172a, #17233b)';
                btn.appendChild(preview);
                var label = document.createElement('div');
                label.textContent = p.name;
                btn.appendChild(label);
                btn.addEventListener('click', function() {
                    saveSetting('platform-wm', 'desktop_background', p.value).then(load).catch(function(e){ alert(e.message); });
                });
                wrap.appendChild(btn);
            });
        }

        function escapeHtml(s) {
            if (!s) return '';
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        document.getElementById('scope').addEventListener('change', function() {
            refreshScopeBadge();
            load();
        });

        renderWmPresets();
        refreshScopeBadge();
        load();
    })();
    </script>
</body>
</html>
HTML;
        $resource->setContent($html);
        $resource->setHeader('Content-Type', 'text/html; charset=utf-8');
        return $resource;
    }

    private function canManageGlobalSettings(): bool
    {
        if ($this->auth->isGuest()) {
            return false;
        }

        try {
            $rbacClass = 'Semitexa\\Platform\\User\\Domain\\Service\\RbacServiceInterface';
            if (!interface_exists($rbacClass) && !class_exists($rbacClass)) {
                return false;
            }
            $rbac = $this->container?->get($rbacClass);
        } catch (\Throwable) {
            $rbac = null;
        }

        if ($rbac === null) {
            return false;
        }

        return $rbac->userHasPermission($this->auth->getUser()->getId(), 'platform.settings.manage_global');
    }
}
