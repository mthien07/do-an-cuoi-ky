(function () {
    document.addEventListener('click', function (e) {
        var openTrigger = e.target.closest('[data-modal-open]');
        if (openTrigger) {
            e.preventDefault();
            var modalId = openTrigger.getAttribute('data-modal-open');
            var dialog = document.getElementById(modalId);
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
            return;
        }

        var closeTrigger = e.target.closest('[data-modal-close]');
        if (closeTrigger) {
            e.preventDefault();
            var parentDialog = closeTrigger.closest('dialog');
            if (parentDialog && typeof parentDialog.close === 'function') {
                parentDialog.close();
            }
            return;
        }

        if (e.target.tagName === 'DIALOG' && e.target.classList.contains('modal-dialog')) {
            var rect = e.target.getBoundingClientRect();
            var clickedInDialog = (
                rect.top <= e.clientY &&
                e.clientY <= rect.top + rect.height &&
                rect.left <= e.clientX &&
                e.clientX <= rect.left + rect.width
            );
            if (!clickedInDialog) {
                e.target.close();
            }
        }
    });

    document.addEventListener('click', function (e) {
        var fillBtn = e.target.closest('[data-fill-user]');
        if (!fillBtn) return;

        e.preventDefault();
        var userVal = fillBtn.getAttribute('data-fill-user') || '';
        var passVal = fillBtn.getAttribute('data-fill-pass') || '';

        var userInput = document.getElementById('identifier') || document.querySelector('input[name="identifier"]');
        var passInput = document.getElementById('password') || document.querySelector('input[name="password"]');

        if (userInput) {
            userInput.value = userVal;
        }
        if (passInput) {
            passInput.value = passVal;
        }
        if (userInput) {
            userInput.focus();
        }
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        var confirmMsg = form.getAttribute('data-confirm');
        if (confirmMsg && !window.confirm(confirmMsg)) {
            e.preventDefault();
        }
    });

    document.addEventListener('click', function (e) {
        var confirmBtn = e.target.closest('[data-confirm-click]');
        if (confirmBtn) {
            var msg = confirmBtn.getAttribute('data-confirm-click');
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
            }
        }
    });

    var quickFilters = document.querySelectorAll('[data-quick-filter]');
    quickFilters.forEach(function (input) {
        var targetSelector = input.getAttribute('data-quick-filter');
        input.addEventListener('input', function () {
            var keyword = input.value.trim().toLowerCase();
            var items = document.querySelectorAll(targetSelector);
            items.forEach(function (item) {
                var text = (item.textContent || '').toLowerCase();
                item.style.display = text.indexOf(keyword) !== -1 ? '' : 'none';
            });
        });
    });
})();
