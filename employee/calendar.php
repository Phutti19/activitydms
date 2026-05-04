<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('employee');

$page_title  = 'ปฏิทินของฉัน';
$page_active = 'my_calendar';
$extra_head  = '<style>
#calendar { min-height: 480px; }
.fc-event { cursor: pointer; }
.fc-event-title { font-weight: 500; }
</style>';
require __DIR__ . '/../includes/header.php';
$app_url_safe = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
?>

<div class="page-header">
    <h1 class="page-title">ปฏิทินของฉัน</h1>
</div>

<!-- Legend -->
<div class="card p-3 mb-3">
    <div class="d-flex gap-3 flex-wrap align-items-center small">
        <span class="fw-medium">สัญลักษณ์:</span>
        <span><span class="badge bg-primary me-1">&nbsp;</span>กิจกรรมองค์กร (ลงทะเบียน)</span>
        <span><span class="badge me-1" style="background:#F59E0B;">&nbsp;</span>📢 เปิดรับสมัคร</span>
        <span><span class="badge bg-secondary me-1">&nbsp;</span>ขาด</span>
        <span><span class="badge" style="background:#64748B;">&nbsp;</span>🔒 กิจกรรมส่วนตัว</span>
    </div>
</div>

<div class="card p-3">
    <div id="calendar"></div>
</div>

<!-- Tooltip -->
<div id="calTooltip" class="card shadow-sm p-3"
     style="position:fixed;z-index:9999;max-width:260px;display:none;pointer-events:none;font-size:13px;">
    <div id="calTooltipContent"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var calEl = document.getElementById('calendar');
    var tooltip = document.getElementById('calTooltip');
    var tooltipContent = document.getElementById('calTooltipContent');

    var cal = new FullCalendar.Calendar(calEl, {
        initialView: window.innerWidth < 768 ? 'listMonth' : 'dayGridMonth',
        locale: 'th',
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,listMonth'
        },
        buttonText: {
            today: 'วันนี้',
            month: 'เดือน',
            list:  'รายการ'
        },
        height: 'auto',
        events: {
            url: '<?= $app_url_safe ?>/api/calendar_events.php',
            failure: function() { alert('โหลดกิจกรรมไม่สำเร็จ'); }
        },
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            if (info.event.url) window.location.href = info.event.url;
        },
        eventMouseEnter: function(info) {
            var ep = info.event.extendedProps;
            tooltipContent.replaceChildren();

            var titleEl = document.createElement('div');
            titleEl.className = 'fw-semibold';
            titleEl.textContent = info.event.title || '';
            tooltipContent.appendChild(titleEl);

            if (ep.status) {
                var statusColor = {'attended':'success','absent':'danger','registered':'secondary'}[ep.status] || 'secondary';
                var statusLabel = {'attended':'เข้าร่วมแล้ว','absent':'ขาด','registered':'ลงทะเบียน'}[ep.status] || '';
                var sb = document.createElement('span');
                sb.className = 'badge bg-' + statusColor + ' mt-1';
                sb.textContent = statusLabel;
                tooltipContent.appendChild(sb);
            }

            if (ep.location) {
                var locEl = document.createElement('div');
                locEl.className = 'text-muted mt-1';
                var icon = document.createElement('i');
                icon.className = 'bi bi-geo-alt';
                locEl.appendChild(icon);
                locEl.appendChild(document.createTextNode(' ' + ep.location));
                tooltipContent.appendChild(locEl);
            }
            tooltip.style.display = 'block';
        },
        eventMouseLeave: function() {
            tooltip.style.display = 'none';
        }
    });
    cal.render();

    document.addEventListener('mousemove', function(e) {
        if (tooltip.style.display !== 'none') {
            var x = e.clientX + 12, y = e.clientY + 12;
            if (x + 270 > window.innerWidth) x = e.clientX - 272;
            tooltip.style.left = x + 'px';
            tooltip.style.top  = y + 'px';
        }
    });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
