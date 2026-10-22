// Sipway Campus - Results System JS

function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (sidebar) sidebar.classList.toggle('show');
    if (overlay) overlay.classList.toggle('show');
}

document.addEventListener('DOMContentLoaded', function () {
    // Auto-dismiss alerts
    document.querySelectorAll('.alert-dismissible').forEach(function (alert) {
        setTimeout(function () {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // Confirm delete
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(this.getAttribute('data-confirm'))) {
                e.preventDefault();
            }
        });
    });

    // Auto-calculate full marks
    const a1 = document.getElementById('assignment1');
    const a2 = document.getElementById('assignment2');
    const fe = document.getElementById('final_exam');
    const full = document.getElementById('full_marks');

    function calcFull() {
        if (!full) return;
        const v1 = parseFloat(a1?.value) || 0;
        const v2 = parseFloat(a2?.value) || 0;
        const v3 = parseFloat(fe?.value) || 0;
        if (a1 || a2 || fe) {
            full.value = (v1 + v2 + v3).toFixed(2);
        }
    }
    if (a1) a1.addEventListener('input', calcFull);
    if (a2) a2.addEventListener('input', calcFull);
    if (fe) fe.addEventListener('input', calcFull);

    // Live search
    const searchInput = document.getElementById('tableSearch');
    if (searchInput) {
        searchInput.addEventListener('keyup', function () {
            const filter = this.value.toLowerCase();
            document.querySelectorAll('#dataTable tbody tr').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(filter) ? '' : 'none';
            });
        });
    }

    // Close sidebar on nav click (mobile)
    document.querySelectorAll('.sidebar .nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth < 992) toggleSidebar();
        });
    });
});
