/**
 * Poll pending Catalog thumbnails and swap in the real JPEG when ready.
 * Marks: img.mm-thumb[data-file-id] with data-pending="1"
 */
(function (global) {
  'use strict';

  function pollOne(img) {
    var id = img.getAttribute('data-file-id');
    if (!id) return;
    var large = img.getAttribute('data-size') === 'large';
    var url = '/queue/thumbnail/' + id + '/status' + (large ? '?size=large' : '');
    fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ready) return;
        img.removeAttribute('data-pending');
        img.src = '/queue/thumbnail/' + id
          + (large ? '?size=large&' : '?')
          + 't=' + Date.now();
        img.classList.remove('d-none');
        var fallback = img.parentElement
          ? img.parentElement.querySelector('.queue-thumb-fallback')
          : null;
        if (fallback) fallback.classList.add('d-none');
      })
      .catch(function () { /* ignore */ });
  }

  function tick() {
    var nodes = document.querySelectorAll('img.mm-thumb[data-pending="1"]');
    if (!nodes.length) return;
    nodes.forEach(pollOne);
  }

  function start(intervalMs) {
    tick();
    setInterval(tick, intervalMs || 4000);
  }

  global.ThumbPoll = { start: start, tick: tick };
})(window);
