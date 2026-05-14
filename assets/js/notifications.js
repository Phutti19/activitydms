// In-app notification (bell) + real-time polling (มติประชุม 2026-05-14)
// Polling ทุก 30s — ใช้กับทุกหน้าที่มี header
(function () {
    'use strict';

    const APP_URL    = window.__APP_URL__   || '';
    const CSRF_TOKEN = window.__CSRF_TOKEN__ || '';
    const CSRF_NAME  = window.__CSRF_NAME__  || 'csrf_token';
    const POLL_MS    = 30000;

    const bellBtn   = document.getElementById('notifBellBtn');
    const badge     = document.getElementById('notifBadge');
    const listEl    = document.getElementById('notifList');
    const markAllEl = document.getElementById('notifMarkAll');

    if (!bellBtn || !badge || !listEl) return;

    let listLoaded = false;
    let pollTimer  = null;

    const fmtTime = (s) => {
        const d = new Date(s.replace(' ', 'T'));
        if (isNaN(d.getTime())) return s;
        const diff = (Date.now() - d.getTime()) / 1000;
        if (diff < 60)     return 'เมื่อสักครู่';
        if (diff < 3600)   return Math.floor(diff / 60)   + ' นาทีก่อน';
        if (diff < 86400)  return Math.floor(diff / 3600) + ' ชั่วโมงก่อน';
        if (diff < 604800) return Math.floor(diff / 86400) + ' วันก่อน';
        return d.toLocaleDateString('th-TH');
    };

    const setBadge = (n) => {
        const v = parseInt(n, 10) || 0;
        if (v <= 0) {
            badge.style.display = 'none';
            badge.textContent = '0';
        } else {
            badge.style.display = '';
            badge.textContent = v > 99 ? '99+' : String(v);
        }
    };

    const iconFor = (type) => {
        switch (type) {
            case 'new_activity':    return 'bi-calendar-event text-primary';
            case 'new_certificate': return 'bi-award text-warning';
            default:                return 'bi-info-circle text-secondary';
        }
    };

    const escapeHtml = (s) => String(s ?? '')
        .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;').replaceAll("'", '&#39;');

    const renderList = (items) => {
        if (!items || items.length === 0) {
            listEl.innerHTML = '<div class="text-center text-muted small py-4">'
                + '<i class="bi bi-bell-slash" style="font-size:24px;opacity:0.4;"></i>'
                + '<div class="mt-1">ไม่มีการแจ้งเตือน</div></div>';
            return;
        }
        const rows = items.map(it => {
            const unreadCls = it.is_read == 0 ? 'bg-light' : '';
            const link = it.link_url ? (APP_URL + escapeHtml(it.link_url)) : '#';
            return `
                <a href="${link}" class="d-block text-decoration-none text-dark p-2 px-3 border-bottom notif-item ${unreadCls}"
                   data-id="${parseInt(it.id, 10)}">
                    <div class="d-flex gap-2 align-items-start">
                        <i class="bi ${iconFor(it.type)} fs-5"></i>
                        <div class="flex-grow-1 small">
                            <div class="fw-semibold text-truncate">${escapeHtml(it.title)}</div>
                            ${it.message ? `<div class="text-muted" style="font-size:12px;">${escapeHtml(it.message)}</div>` : ''}
                            <div class="text-muted" style="font-size:11px;">${escapeHtml(fmtTime(it.created_at))}</div>
                        </div>
                        ${it.is_read == 0 ? '<span class="badge bg-primary rounded-pill" style="font-size:9px;">ใหม่</span>' : ''}
                    </div>
                </a>
            `;
        }).join('');
        listEl.innerHTML = rows;
    };

    const fetchList = async () => {
        try {
            const r = await fetch(APP_URL + '/api/notifications.php?action=list&limit=10', {
                credentials: 'same-origin',
            });
            if (!r.ok) return;
            const data = await r.json();
            if (!data.ok) return;
            renderList(data.items);
            setBadge(data.unread_count);
            listLoaded = true;
        } catch (e) { /* network — silent */ }
    };

    const fetchCount = async () => {
        try {
            const r = await fetch(APP_URL + '/api/notifications.php?action=count', {
                credentials: 'same-origin',
            });
            if (!r.ok) return;
            const data = await r.json();
            if (data.ok) setBadge(data.unread_count);
        } catch (e) { /* silent */ }
    };

    const markRead = async (id) => {
        const body = new URLSearchParams({ action: 'mark_read', id: id });
        body.append(CSRF_NAME, CSRF_TOKEN);
        try {
            const r = await fetch(APP_URL + '/api/notifications.php', {
                method: 'POST', credentials: 'same-origin', body,
            });
            const data = await r.json();
            if (data.ok) setBadge(data.unread_count);
        } catch (e) {}
    };

    const markAll = async () => {
        const body = new URLSearchParams({ action: 'mark_all_read' });
        body.append(CSRF_NAME, CSRF_TOKEN);
        try {
            const r = await fetch(APP_URL + '/api/notifications.php', {
                method: 'POST', credentials: 'same-origin', body,
            });
            const data = await r.json();
            if (data.ok) {
                setBadge(0);
                fetchList();
            }
        } catch (e) {}
    };

    bellBtn.addEventListener('show.bs.dropdown', () => {
        if (!listLoaded) fetchList();
    });

    listEl.addEventListener('click', (ev) => {
        const item = ev.target.closest('.notif-item');
        if (!item) return;
        const id = item.dataset.id;
        if (id) markRead(id);
        // ปล่อย link navigate ต่อ
    });

    if (markAllEl) {
        markAllEl.addEventListener('click', (ev) => {
            ev.preventDefault();
            markAll();
        });
    }

    // Real-time polling 30s — เคารพ visibility (หยุดเมื่อ tab อยู่หลังบ้าน)
    const startPolling = () => {
        if (pollTimer) return;
        pollTimer = setInterval(() => {
            if (document.hidden) return;
            if (listLoaded) {
                fetchList();
            } else {
                fetchCount();
            }
        }, POLL_MS);
    };
    const stopPolling = () => {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    };

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stopPolling();
        else { fetchCount(); startPolling(); }
    });

    startPolling();
})();
