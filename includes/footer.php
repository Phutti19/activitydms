    </main>
</div>

<script src="<?= h(APP_URL) ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
// กิน AbortError จาก View Transitions API (เกิดเมื่อ transition ถูก abort กลางคัน — ไม่ใช่ bug)
window.addEventListener('unhandledrejection', (e) => {
    if (e.reason && e.reason.name === 'AbortError'
        && /Transition was skipped/i.test(e.reason.message || '')) {
        e.preventDefault();
    }
});

// ย้าย focus ออกก่อน modal ปิด — กัน a11y warning "aria-hidden on focused element"
document.addEventListener('hide.bs.modal', (e) => {
    const active = document.activeElement;
    if (active && e.target.contains(active)) {
        active.blur();
    }
});
</script>
<script src="<?= h(APP_URL) ?>/assets/js/notifications.js?v=<?= @filemtime(__DIR__ . '/../assets/js/notifications.js') ?: time() ?>" defer></script>
<script src="<?= h(APP_URL) ?>/assets/js/autofilter.js?v=<?= @filemtime(__DIR__ . '/../assets/js/autofilter.js') ?: time() ?>" defer></script>
<?= $extra_scripts ?? '' ?>
</body>
</html>
