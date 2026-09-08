/* Apotek Sehat — interaksi UI ringan (tanpa dependensi tambahan) */
(function () {
  'use strict';

  // Toggle sidebar di layar kecil
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  const burger  = document.getElementById('btnHamburger');

  function openSidebar()  { sidebar?.classList.add('open');  overlay?.classList.add('show'); }
  function closeSidebar() { sidebar?.classList.remove('open'); overlay?.classList.remove('show'); }

  burger?.addEventListener('click', openSidebar);
  overlay?.addEventListener('click', closeSidebar);

  // Konfirmasi sebelum aksi hapus (form dengan class .js-confirm-delete)
  document.querySelectorAll('.js-confirm-delete').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      const nama = form.dataset.name || 'data ini';
      if (!confirm('Hapus ' + nama + '? Tindakan ini tidak dapat dibatalkan.')) {
        ev.preventDefault();
      }
    });
  });

  // Pencarian tabel sederhana (input[data-table-search] menyaring tbody tabel target)
  document.querySelectorAll('[data-table-search]').forEach(function (input) {
    const target = document.querySelector(input.getAttribute('data-table-search'));
    if (!target) return;
    input.addEventListener('input', function () {
      const q = input.value.toLowerCase().trim();
      target.querySelectorAll('tbody tr').forEach(function (row) {
        if (row.classList.contains('js-no-filter')) return;
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
      });
    });
  });

  // Auto-dismiss flash alert setelah 4 detik
  document.querySelectorAll('.js-auto-dismiss').forEach(function (el) {
    setTimeout(function () {
      el.style.transition = 'opacity .4s';
      el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 400);
    }, 4000);
  });
})();
