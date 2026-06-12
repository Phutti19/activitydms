<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');

$page_title  = 'ปฏิทินกิจกรรม';
$page_active = 'calendar';
$extra_head  = '<style>
#calendar { min-height: 500px; }
.fc-event { cursor: pointer; }
.fc-event-title { font-weight: 500; }
</style>';
require __DIR__ . '/../includes/header.php';
$app_url_safe = htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8');
?>

<div class="page-header">
    <h1 class="page-title">ปฏิทินกิจกรรม</h1>
    <a href="<?= $app_url_safe ?>/admin/activity_form.php?action=create"
       class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>เพิ่มกิจกรรม
    </a>
</div>

<div class="card p-3">
    <div id="calendar"></div>
</div>

<!-- Event tooltip -->
<div id="calTooltip" class="card shadow-sm p-3"
     style="position:fixed;z-index:9999;max-width:280px;display:none;pointer-events:none;font-size:13px;">
    <div id="calTooltipContent"></div>
</div>

<!-- FullCalendar CDN -->
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
            right:  'dayGridMonth,timeGridWeek,listMonth'
        },
        buttonText: {
            today:       'วันนี้',
            month:       'เดือน',
            week:        'สัปดาห์',
            list:        'รายการ'
        },
        height: 'auto',
        events: {
            url: '<?= $app_url_safe ?>/api/calendar_events.php',
            failure: function() {
                alert('โหลดกิจกรรมไม่สำเร็จ');
            }
        },
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            if (info.event.url) {
                window.location.href = info.event.url;
            }
        },
        eventMouseEnter: function(info) {
            var ep = info.event.extendedProps;
            // Build via DOM API + textContent — กัน XSS จากค่าใน DB
            tooltipContent.replaceChildren();

            var titleEl = document.createElement('div');
            titleEl.className = 'fw-semibold mb-1';
            titleEl.textContent = info.event.title || '';
            tooltipContent.appendChild(titleEl);

            if (ep.type) {
                var typeWrap = document.createElement('div');
                typeWrap.className = 'mb-1';
                var typeBadge = document.createElement('span');
                typeBadge.className = 'badge bg-secondary me-1';
                typeBadge.textContent = ep.type;
                typeWrap.appendChild(typeBadge);
                tooltipContent.appendChild(typeWrap);
            }

            if (ep.location) {
                var locEl = document.createElement('div');
                locEl.className = 'text-muted';
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
        },
        eventDidMount: function(info) {
            // Mobile: show in list view by default
        }
    });
    cal.render();

    document.addEventListener('mousemove', function(e) {
        if (tooltip.style.display !== 'none') {
            var x = e.clientX + 12;
            var y = e.clientY + 12;
            // prevent overflow right
            if (x + 290 > window.innerWidth) x = e.clientX - 292;
            tooltip.style.left = x + 'px';
            tooltip.style.top  = y + 'px';
        }
    });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
