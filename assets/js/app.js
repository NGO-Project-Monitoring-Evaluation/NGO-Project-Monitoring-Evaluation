// Small behaviours; the Content Security Policy forbids inline scripts, so they live here.
document.addEventListener('submit', function (e) {
  var msg = e.target.getAttribute('data-confirm');
  if (msg && !window.confirm(msg)) { e.preventDefault(); }
});
document.addEventListener('click', function (e) {
  var t = e.target.closest('[data-print]');
  if (t) { e.preventDefault(); window.print(); }
});
// Show the period format that matches the indicator's reporting period.
(function () {
  var el = document.getElementById('period_label');
  if (!el) return;
  var hint = document.getElementById('period_hint');
  var hints = { Monthly: 'YYYY-MM, for example 2026-03', Quarterly: 'YYYY-Qn, for example 2026-Q1', Annual: 'YYYY, for example 2026' };
  var freq = el.getAttribute('data-frequency');
  if (hint && hints[freq]) { hint.textContent = 'Format: ' + hints[freq]; }
})();
// Show only the filters that apply to the selected report type.
(function () {
  var sel = document.getElementById('report_type');
  if (!sel) return;
  function apply() {
    document.querySelectorAll('[data-for]').forEach(function (n) {
      var types = n.getAttribute('data-for').split(' ');
      var active = types.indexOf(sel.value) !== -1;
      n.hidden = !active;
      // Hidden controls must not be submitted. The report form has two status
      // selectors (activity and commitment); leaving both enabled caused the
      // browser to submit duplicate status values and filters to behave erratically.
      n.querySelectorAll('input, select, textarea, button').forEach(function (field) {
        field.disabled = !active;
      });
    });
  }
  sel.addEventListener('change', apply); apply();
})();
