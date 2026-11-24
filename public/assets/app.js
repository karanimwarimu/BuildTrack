// Small progressive enhancements; every page works without JavaScript.
document.addEventListener('DOMContentLoaded', function () {
  var body = document.body;
  document.querySelectorAll('[data-nav-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () { body.classList.toggle('nav-open'); });
  });
  document.querySelectorAll('[data-nav-close]').forEach(function (el) {
    el.addEventListener('click', function () { body.classList.remove('nav-open'); });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') body.classList.remove('nav-open'); });
  // Demo login buttons fill in the credentials (public demo only).
  document.querySelectorAll('[data-demo-login]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form = document.querySelector('form[action*="page=login"]');
      if (!form) return;
      form.querySelector('[name=email]').value = btn.getAttribute('data-demo-login');
      var code = document.querySelector('.demo-accounts code');
      form.querySelector('[name=password]').value = code ? code.textContent : '';
      form.submit();
    });
  });
  // <select data-autosubmit> submits its form on change (used by the progress task picker).
  document.querySelectorAll('select[data-autosubmit]').forEach(function (sel) {
    sel.addEventListener('change', function () { sel.form.submit(); });
  });
});
