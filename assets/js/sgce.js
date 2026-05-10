(function () {
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-sgce-confirm]').forEach(function (el) {
      el.addEventListener('click', function (ev) {
        const msg = el.getAttribute('data-sgce-confirm') || '¿Confirmar acción?';
        if (!confirm(msg)) ev.preventDefault();
      });
    });
  });
})();
