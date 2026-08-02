/**
 * Lightweight status poller — patches DOM from JSON without full page reload.
 * Used by scan/caption job detail and Continuity Lab live mode.
 */
(function (global) {
  'use strict';

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function setText(id, text) {
    var el = typeof id === 'string' ? document.getElementById(id) : id;
    if (!el) return;
    var next = text == null ? '' : String(text);
    if (el.textContent !== next) {
      el.textContent = next;
    }
  }

  function setHtml(id, html) {
    var el = typeof id === 'string' ? document.getElementById(id) : id;
    if (!el) return;
    var next = html == null ? '' : String(html);
    if (el.innerHTML !== next) {
      el.innerHTML = next;
    }
  }

  function setWidth(id, pct) {
    var el = document.getElementById(id);
    if (!el) return;
    var w = Math.max(0, Math.min(100, Number(pct) || 0)) + '%';
    if (el.style.width !== w) {
      el.style.width = w;
    }
  }

  /**
   * @param {object} opts
   * @param {string} opts.url
   * @param {number} [opts.intervalMs]
   * @param {(data: object) => void} opts.onData
   * @param {() => boolean} [opts.shouldSkip]
   * @param {(data: object) => boolean} [opts.shouldStop] return true to stop polling
   * @param {() => void} [opts.onStop]
   * @param {() => void} [opts.onAuthLost]
   */
  function start(opts) {
    var url = opts.url;
    var intervalMs = opts.intervalMs || 5000;
    var onData = opts.onData;
    var shouldSkip = opts.shouldSkip || function () { return false; };
    var shouldStop = opts.shouldStop || function () { return false; };
    var onStop = opts.onStop || function () {};
    var onAuthLost = opts.onAuthLost || function () {
      global.location.reload();
    };
    var timer = null;
    var inFlight = false;
    var stopped = false;

    function stop() {
      if (stopped) return;
      stopped = true;
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
      onStop();
    }

    function poll() {
      if (stopped || inFlight) return;
      if (document.hidden) return;
      if (shouldSkip()) return;

      inFlight = true;
      fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      })
        .then(function (r) {
          if (r.status === 401 || r.status === 403) {
            onAuthLost();
            stop();
            return null;
          }
          var ct = r.headers.get('content-type') || '';
          if (!r.ok || ct.indexOf('application/json') === -1) {
            throw new Error('Bad status response');
          }
          return r.json();
        })
        .then(function (data) {
          if (!data || stopped) return;
          onData(data);
          if (shouldStop(data) || data.poll === false) {
            stop();
          }
        })
        .catch(function () {
          /* keep polling — transient errors */
        })
        .finally(function () {
          inFlight = false;
        });
    }

    timer = setInterval(poll, intervalMs);
    poll();

    return { stop: stop, poll: poll };
  }

  global.LivePoll = {
    start: start,
    escapeHtml: escapeHtml,
    setText: setText,
    setHtml: setHtml,
    setWidth: setWidth
  };
})(window);
