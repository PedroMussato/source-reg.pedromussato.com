/* Progressive enhancement only: the form works without JavaScript. */
(function () {
  var form = document.getElementById('setup-form');
  if (!form) return;
  form.addEventListener('submit', function () {
    var btn = document.getElementById('setup-submit');
    var msg = document.getElementById('setup-progress');
    if (btn) { btn.disabled = true; btn.textContent = 'Cloning…'; }
    if (msg) msg.hidden = false;
  });
})();
