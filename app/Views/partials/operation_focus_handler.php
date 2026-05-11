<?php
declare(strict_types=1);
?>
<style>
.operation-focus {
    outline: 3px solid var(--brand) !important;
    outline-offset: 5px;
    box-shadow: 0 0 0 6px rgba(212, 175, 55, 0.22);
    border-radius: var(--radius-md, 14px);
    position: relative;
    z-index: 2;
}
.operation-focus-anchor-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 10px;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 0.88rem;
    font-weight: 600;
    background: rgba(212, 175, 55, 0.18);
    border: 1px solid var(--line-strong);
    color: var(--ink);
}
</style>
<script>
(function () {
    try {
        var params = new URLSearchParams(window.location.search);
        var raw = params.get('focus');
        if (!raw || raw.indexOf(':') < 0) {
            return;
        }
        var parts = raw.split(':');
        var kind = (parts[0] || '').trim();
        var id = (parts.slice(1).join(':') || '').trim();
        if (!kind || !id) {
            return;
        }
        var safeKind = kind.replace(/[^a-z0-9_-]/gi, '');
        var safeId = id.replace(/[^a-z0-9_-]/gi, '');

        function revealHistoryExtras(el) {
            var host = el.closest('[data-history-group]');
            if (!host) {
                return;
            }
            var gid = host.getAttribute('data-history-group');
            if (!gid) {
                return;
            }
            document.querySelectorAll('[data-history-group="' + gid + '"].history-extra').forEach(function (node) {
                if (node.style) {
                    node.style.display = '';
                }
                node.classList.remove('history-extra');
            });
            var btn = document.querySelector('button[data-history-toggle="' + gid + '"]');
            if (btn) {
                btn.style.display = 'none';
            }
        }

        function openAncestorDetails(el) {
            var p = el;
            while (p && p !== document.body) {
                if (p.tagName === 'DETAILS' && !p.open) {
                    p.open = true;
                }
                p = p.parentElement;
            }
        }

        var el = document.querySelector('[data-operation-focus="' + kind + ':' + id.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]');
        if (!el) {
            el = document.getElementById('op-focus-' + safeKind + '-' + safeId);
        }
        if (!el && kind === 'server_request') {
            el = document.querySelector('[data-server-request-id="' + id.replace(/"/g, '') + '"]');
        }
        if (!el) {
            return;
        }

        revealHistoryExtras(el);
        openAncestorDetails(el);

        el.classList.add('operation-focus');
        var badge = document.createElement('div');
        badge.className = 'operation-focus-anchor-badge';
        badge.setAttribute('role', 'status');
        badge.textContent = 'Opération à régulariser · ' + kind + ' #' + id;
        var holder = el.closest('section.card') || el.closest('section') || el.closest('.card');
        if (holder && holder !== el) {
            var wrap = document.createElement('div');
            wrap.className = 'no-print';
            wrap.style.cssText = 'padding:0 22px 12px;';
            wrap.appendChild(badge);
            holder.insertBefore(wrap, holder.firstChild);
        }

        window.setTimeout(function () {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 120);
    } catch (e) {
        /* silencieux : ne pas bloquer la page */
    }
})();
</script>
