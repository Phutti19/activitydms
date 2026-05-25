// Admin activity_view — photos / attachments / attendance management
// Config มาจาก window.ACTIVITY_VIEW (inject ใน activity_view.php)
const CFG = window.ACTIVITY_VIEW || {};
const ACTIVITY_ID = CFG.id;
const CSRF_TOKEN = CFG.csrfToken;
const CSRF_NAME = CFG.csrfName;
const APP_BASE = CFG.appBase;
const MAX_IMAGE_BYTES = CFG.maxImageBytes;
const MAX_IMAGE_MB = CFG.maxImageMb;

let currentPhotoCount = CFG.photoCount;

window.addEventListener('load', () => {
    const hash = window.location.hash.replace('#', '');
    if (!hash) return;
    const trigger = document.querySelector(`[data-bs-target="#${hash}"]`);
    if (trigger && typeof bootstrap !== 'undefined') {
        new bootstrap.Tab(trigger).show();
    }
});

function toggleAttType() {
    const isFile = document.getElementById('attTypeFile').checked;
    document.getElementById('attFileWrap').classList.toggle('d-none', !isFile);
    document.getElementById('attUrlWrap').classList.toggle('d-none', isFile);
    document.getElementById('attFile').required = isFile;
    document.getElementById('attUrl').required = !isFile;
}
document.getElementById('attTypeFile').addEventListener('change', toggleAttType);
document.getElementById('attTypeUrl').addEventListener('change', toggleAttType);
toggleAttType();

// ===== Bulk remove participants (event delegation) =====
function updateBulkBar() {
    const bar = document.getElementById('bulkBar');
    const cnt = document.getElementById('bulkCount');
    const selAllEl = document.getElementById('selAll');
    const checks = document.querySelectorAll('.reg-check');
    if (!bar || !checks.length) return;
    const checkedCount = document.querySelectorAll('.reg-check:checked').length;
    if (cnt) cnt.textContent = checkedCount;
    bar.classList.toggle('d-none', checkedCount === 0);
    if (selAllEl) selAllEl.checked = (checkedCount === checks.length && checkedCount > 0);
}

document.addEventListener('change', (e) => {
    if (e.target.id === 'selAll') {
        document.querySelectorAll('.reg-check').forEach(c => c.checked = e.target.checked);
        updateBulkBar();
    } else if (e.target.classList && e.target.classList.contains('reg-check')) {
        updateBulkBar();
    }
});

document.addEventListener('click', (e) => {
    const btn = e.target.closest('.remove-one-btn');
    if (!btn) return;
    if (!confirm('ลบ "' + btn.dataset.name + '" ออกจากรายชื่อ?')) return;
    const idEl = document.getElementById('removeOneRegId');
    const fmEl = document.getElementById('removeOneForm');
    if (idEl && fmEl) {
        idEl.value = btn.dataset.regId;
        fmEl.submit();
    }
});

// ===== Per-row status change =====
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.change-status-btn');
    if (!btn) return;
    const fm = document.getElementById('updateOneForm');
    const idEl = document.getElementById('updateOneRegId');
    const stEl = document.getElementById('updateOneNewStatus');
    if (fm && idEl && stEl) {
        idEl.value = btn.dataset.regId;
        stEl.value = btn.dataset.newStatus;
        fm.submit();
    }
});

// ===== Bulk attendance buttons =====
document.addEventListener('click', (e) => {
    const btn = e.target.closest('.bulk-status-btn');
    if (!btn) return;
    const checked = document.querySelectorAll('.reg-check:checked').length;
    if (checked === 0) return;
    const labels = { attended: 'เข้าร่วม', absent: 'ไม่เข้าร่วม', registered: 'รอเช็ค' };
    const status = btn.dataset.status;
    if (!confirm('เปลี่ยนสถานะของ ' + checked + ' คนเป็น "' + (labels[status] || status) + '"?')) return;
    document.getElementById('bulkAction').value = 'update_attendance';
    document.getElementById('bulkNewStatus').value = status;
    document.getElementById('bulkAttendanceForm').submit();
});

// ===== Bulk remove button =====
document.addEventListener('click', (e) => {
    const btn = e.target.closest('#bulkRemoveBtn');
    if (!btn) return;
    const checked = document.querySelectorAll('.reg-check:checked').length;
    if (checked === 0) return;
    if (!confirm('ลบผู้เข้าร่วมที่เลือก ' + checked + ' คน?')) return;
    document.getElementById('bulkAction').value = 'remove_participants_bulk';
    document.getElementById('bulkNewStatus').value = '';
    document.getElementById('bulkAttendanceForm').submit();
});

// ===== Add participants modal — search filter + selection counter (event delegation) =====
function visibleUserChecksList() {
    return Array.from(document.querySelectorAll('.user-check')).filter(c => {
        const label = c.closest('.user-item');
        return label && label.style.display !== 'none';
    });
}
function updateAddCount() {
    const checked = document.querySelectorAll('.user-check:checked').length;
    const cnt = document.getElementById('addSelCount');
    const submit = document.getElementById('addSubmit');
    const selectAllBtn = document.getElementById('selectAllUsers');
    if (cnt) cnt.textContent = checked;
    if (submit) submit.disabled = checked === 0;
    if (selectAllBtn) {
        const visible = visibleUserChecksList();
        const allChecked = visible.length > 0 && visible.every(c => c.checked);
        selectAllBtn.innerHTML = allChecked
            ? '<i class="bi bi-x-square me-1"></i> ยกเลิกทั้งหมด'
            : '<i class="bi bi-check2-square me-1"></i> เลือกทั้งหมด';
    }
}

document.addEventListener('change', (e) => {
    if (e.target.classList && e.target.classList.contains('user-check')) {
        updateAddCount();
    }
});

document.addEventListener('input', (e) => {
    if (e.target.id !== 'userSearch') return;
    const q = e.target.value.toLowerCase().trim();
    document.querySelectorAll('.user-item').forEach(item => {
        const data = item.dataset.search || '';
        item.style.display = (q === '' || data.includes(q)) ? '' : 'none';
    });
});

document.addEventListener('click', (e) => {
    if (e.target.closest('#selectAllUsers')) {
        const visible = visibleUserChecksList();
        if (visible.length === 0) return;
        const allChecked = visible.every(c => c.checked);
        visible.forEach(c => c.checked = !allChecked);
        updateAddCount();
    }
});

// ===== Photo upload (เฉพาะเมื่อมีสิทธิ์เพิ่มภาพ) =====
if (CFG.canAddPhoto) {
    const dropZone = document.getElementById('dropZone');
    const photoInput = document.getElementById('photoInput');
    const photoGrid = document.getElementById('photoGrid');
    const uploadStatus = document.getElementById('uploadStatus');

    dropZone.addEventListener('click', () => photoInput.click());
    dropZone.addEventListener('dragover', e => {
        e.preventDefault();
        dropZone.style.borderColor = '#0EA5E9';
        dropZone.style.background = '#E0F2FE';
    });
    dropZone.addEventListener('dragleave', () => {
        dropZone.style.borderColor = '#CBD5E1';
        dropZone.style.background = '#F8FAFC';
    });
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.style.borderColor = '#CBD5E1';
        dropZone.style.background = '#F8FAFC';
        handleFiles(e.dataTransfer.files);
    });
    photoInput.addEventListener('change', e => handleFiles(e.target.files));

    function showStatus(text, type = 'info') {
        const cls = type === 'error' ? 'alert-danger' : (type === 'success' ? 'alert-success' : 'alert-info');
        const div = document.createElement('div');
        div.className = `alert ${cls} small alert-dismissible fade show`;
        div.innerHTML = text + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        uploadStatus.appendChild(div);
        setTimeout(() => bootstrap.Alert.getOrCreateInstance(div).close(), 5000);
    }

    async function handleFiles(files) {
        for (const file of files) {
            if (currentPhotoCount >= 5) {
                showStatus('ครบ 5 ภาพแล้ว — กรุณารีเฟรชหน้า', 'error');
                return;
            }
            if (file.size > MAX_IMAGE_BYTES) {
                showStatus(`${file.name}: ไฟล์ใหญ่เกิน ${MAX_IMAGE_MB} MB`, 'error');
                continue;
            }
            if (!['image/jpeg','image/png','image/webp'].includes(file.type)) {
                showStatus(`${file.name}: ประเภทไม่อนุญาต (JPG/PNG/WEBP เท่านั้น)`, 'error');
                continue;
            }
            await uploadOne(file);
        }
        photoInput.value = '';
    }

    async function uploadOne(file) {
        const fd = new FormData();
        fd.append(CSRF_NAME, CSRF_TOKEN);
        fd.append('activity_id', ACTIVITY_ID);
        fd.append('photo', file);

        showStatus(`กำลังอัปโหลด ${file.name}...`, 'info');
        try {
            const res = await fetch(APP_BASE + '/api/activity_upload_photo.php', {
                method: 'POST',
                body: fd,
            });
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'อัปโหลดไม่สำเร็จ');

            currentPhotoCount++;
            appendPhoto(data.photo);
            document.getElementById('remainSlot').textContent = (5 - currentPhotoCount).toString();
            if (currentPhotoCount >= 5) {
                dropZone.style.display = 'none';
            }
            showStatus(`อัปโหลด ${file.name} สำเร็จ`, 'success');
        } catch (e) {
            showStatus(`${file.name}: ${e.message}`, 'error');
        }
    }

    function appendPhoto(p) {
        const empty = photoGrid.querySelector('.col-12.text-center');
        if (empty) empty.remove();

        const col = document.createElement('div');
        col.className = 'col-6 col-md-4 col-lg-3';
        col.dataset.photoId = p.id;
        const url = APP_BASE + '/api/download.php?type=photo&id=' + p.id;
        const safeOrig = (p.original_name || '').replace(/[<>"']/g, '');
        col.innerHTML = `
            <div class="card h-100 overflow-hidden position-relative">
                <a href="${url}" target="_blank">
                    <img src="${url}" alt="" style="width:100%;aspect-ratio:1;object-fit:cover;display:block;">
                </a>
                <form method="POST" class="position-absolute top-0 end-0 m-1"
                      onsubmit="return confirm('ลบภาพนี้?');">
                    <input type="hidden" name="${CSRF_NAME}" value="${CSRF_TOKEN}">
                    <input type="hidden" name="action" value="delete_photo">
                    <input type="hidden" name="photo_id" value="${p.id}">
                    <input type="hidden" name="_tab" value="tab-photos">
                    <button type="submit" class="btn btn-sm btn-danger" style="opacity:0.85;">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </form>
                <div class="p-2 small text-muted text-truncate" title="${safeOrig}">${safeOrig}</div>
            </div>`;
        photoGrid.appendChild(col);
    }
}

// ===== Cert upload modal =====
document.querySelectorAll('.upload-cert-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('certUserId').value = btn.dataset.userId;
        document.getElementById('certUserName').textContent = btn.dataset.userName;
        const fi = document.getElementById('certFileInput');
        if (fi) fi.value = '';
        new bootstrap.Modal(document.getElementById('uploadCertModal')).show();
    });
});
