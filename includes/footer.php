    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// กิน AbortError จาก View Transitions API (เกิดเมื่อ transition ถูก abort กลางคัน — ไม่ใช่ bug)
window.addEventListener('unhandledrejection', (e) => {
    if (e.reason && e.reason.name === 'AbortError'
        && /Transition was skipped/i.test(e.reason.message || '')) {
        e.preventDefault();
    }
});
</script>
<script src="<?= htmlspecialchars(APP_URL, ENT_QUOTES, 'UTF-8') ?>/assets/js/notifications.js?v=<?= @filemtime(__DIR__ . '/../assets/js/notifications.js') ?: time() ?>" defer></script>
<?= $extra_scripts ?? '' ?>
</body>
</html>
