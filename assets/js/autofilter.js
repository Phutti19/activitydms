// autofilter.js — กรองอัตโนมัติ ไม่ต้องกดปุ่ม "กรอง"
// ใช้กับฟอร์มที่มี attribute data-autofilter (method=GET)
//   - <select> / checkbox / radio / input[type=date] → submit ทันทีเมื่อเปลี่ยน
//   - input[type=text|search] → หน่วงเวลา (debounce) กันยิงทุกตัวอักษร
// หน่วงเวลาปรับได้ด้วย data-autofilter="600" (ms) ค่าเริ่มต้น 400ms
(function () {
    'use strict';

    document.querySelectorAll('form[data-autofilter]').forEach(function (form) {
        var delay = parseInt(form.getAttribute('data-autofilter'), 10);
        if (isNaN(delay) || delay < 0) delay = 400;

        var submit = function () {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        };

        form.querySelectorAll('select, input[type="date"], input[type="checkbox"], input[type="radio"]')
            .forEach(function (el) {
                el.addEventListener('change', submit);
            });

        form.querySelectorAll('input[type="text"], input[type="search"], input:not([type])')
            .forEach(function (el) {
                var timer;
                el.addEventListener('input', function () {
                    clearTimeout(timer);
                    timer = setTimeout(submit, delay);
                });
            });
    });
})();
