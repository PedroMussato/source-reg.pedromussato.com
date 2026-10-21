/* Runs before first paint (external file: the CSP forbids inline scripts). Prevents a theme flash. */
(function () {
  try {
    var t = localStorage.getItem('wiki-theme');
    if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
  } catch (e) { /* storage unavailable: fall back to the OS preference */ }
})();
