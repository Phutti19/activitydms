// Employee personal_activities — modal edit/create + upload preflight
// Config มาจาก window.PERSONAL_ACTIVITIES (inject ใน personal_activities.php)
const PA_CFG = window.PERSONAL_ACTIVITIES || {};

// ใช้ event delegation + show.bs.modal เพื่อให้ Bootstrap เป็นคนเปิด modal เอง (กัน race + ทำงานได้แม้ปุ่มถูก render ใหม่)
const activityModalEl = document.getElementById('activityModal');
const fAttachLabel    = document.getElementById('fAttachLabel');

document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.edit-btn');
    if (!btn) return;
    const d = btn.dataset;
    document.getElementById('modalEditId').value  = d.id || '0';
    document.getElementById('fTitle').value       = d.title || '';
    document.getElementById('fDesc').value        = d.description || '';
    document.getElementById('fLocation').value    = d.location || '';
    document.getElementById('fType').value        = d.type || '';
    document.getElementById('fFiscal').value      = d.fiscal || '';
    document.getElementById('fStartDate').value   = d.startDate   || '';
    document.getElementById('fStartHour').value   = d.startHour   || '';
    document.getElementById('fStartMinute').value = d.startMinute || '';
    document.getElementById('fEndDate').value     = d.endDate     || '';
    document.getElementById('fEndHour').value     = d.endHour     || '';
    document.getElementById('fEndMinute').value   = d.endMinute   || '';
    const fmt = (d.format === 'online') ? 'online' : 'onsite';
    const rb  = document.querySelector('input[name="format"][value="' + fmt + '"]');
    if (rb) rb.checked = true;
    document.getElementById('modalTitle').textContent = 'แก้ไขกิจกรรมส่วนตัว';
    fAttachLabel.textContent = 'ไฟล์แนบ (เพิ่มไฟล์ใหม่)';
});

document.querySelectorAll('[data-mode="create"]').forEach((btn) => {
    btn.addEventListener('click', () => {
        fAttachLabel.textContent = 'ไฟล์แนบ';
    });
});

// แก้ Bootstrap 5 a11y: ย้าย focus ออกจาก modal ก่อนตั้ง aria-hidden
activityModalEl.addEventListener('hide.bs.modal', () => {
    if (activityModalEl.contains(document.activeElement)) {
        document.activeElement.blur();
    }
});

activityModalEl.addEventListener('hidden.bs.modal', () => {
    document.getElementById('modalEditId').value = '0';
    activityModalEl.querySelector('form').reset();
    document.getElementById('modalTitle').textContent = 'สร้างกิจกรรมส่วนตัว';
    fAttachLabel.textContent = 'ไฟล์แนบ';
});

// Client-side preflight: เตือนถ้าไฟล์เกินเพดาน server (อ่านจาก config)
const fAttach        = document.getElementById('fAttach');
const fAttachHint    = document.getElementById('fAttachServerHint');
const fAttachLimitEl = document.getElementById('fAttachServerLimit');
const SERVER_UPLOAD_LIMIT_BYTES = PA_CFG.uploadLimitBytes || 0;
const SERVER_UPLOAD_LIMIT_LABEL = PA_CFG.uploadLimitLabel || '';
if (SERVER_UPLOAD_LIMIT_BYTES > 0 && SERVER_UPLOAD_LIMIT_BYTES < (10 * 1024 * 1024)) {
    fAttachLimitEl.textContent = SERVER_UPLOAD_LIMIT_LABEL;
    fAttachHint.classList.remove('d-none');
}
function checkOversize(input) {
    if (!input.files || !input.files.length) return;
    if (SERVER_UPLOAD_LIMIT_BYTES <= 0) return;
    const tooBig = Array.from(input.files).filter(f => f.size > SERVER_UPLOAD_LIMIT_BYTES);
    if (tooBig.length) {
        alert('ไฟล์ต่อไปนี้ใหญ่เกินเพดานเซิร์ฟเวอร์ (' + SERVER_UPLOAD_LIMIT_LABEL + '):\n\n'
              + tooBig.map(f => '• ' + f.name + ' (' + (f.size/1024/1024).toFixed(1) + ' MB)').join('\n'));
        input.value = '';
    }
}
fAttach.addEventListener('change', () => checkOversize(fAttach));
document.querySelectorAll('.attach-add-input').forEach((el) => {
    el.addEventListener('change', () => checkOversize(el));
});
