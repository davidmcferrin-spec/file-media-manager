<?php

declare(strict_types=1);

use MediaManager\Support\View;

/** @var array{ok: bool, message: string} $ready */
/** @var array<string, mixed> $system */
/** @var list<array<string, mixed>> $services */
/** @var string $csrf */

$ready = $ready ?? ['ok' => false, 'message' => 'Unknown'];
$system = $system ?? [];
$services = $services ?? [];
$csrf = $csrf ?? '';

$fmtBytes = static function (?int $bytes): string {
    if ($bytes === null || $bytes < 0) {
        return '—';
    }

    return View::filesize($bytes);
};

$fmtUptime = static function (?int $seconds): string {
    if ($seconds === null || $seconds < 0) {
        return '—';
    }
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($d > 0) {
        return sprintf('%dd %dh %dm', $d, $h, $m);
    }
    if ($h > 0) {
        return sprintf('%dh %dm', $h, $m);
    }

    return sprintf('%dm', $m);
};

$load = $system['loadavg'] ?? [null, null, null];
$mem = is_array($system['memory'] ?? null) ? $system['memory'] : null;
$disk = is_array($system['disk'] ?? null) ? $system['disk'] : null;
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Services</h1>
    <p class="mb-0 path-text" style="font-size:0.8rem">
      Systemd workers and host status — live journal tail (like <code>journalctl -f</code>).
      Actions are audited.
    </p>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <span id="services-poll-hint" class="path-text" style="font-size:0.75rem">live 3s</span>
    <button type="button" id="services-refresh-btn" class="btn btn-outline-secondary btn-sm">Refresh now</button>
  </div>
</div>

<input type="hidden" id="services-csrf" value="<?php echo View::e($csrf); ?>">

<?php if (empty($ready['ok'])): ?>
<div class="alert alert-warning mb-4" style="font-size:0.85rem">
  <strong>Services control not ready.</strong>
  <?php echo View::e((string) ($ready['message'] ?? '')); ?>
  <div class="mt-2 path-text" style="font-size:0.78rem">
    On the server (as root), re-run <code>./setup.sh</code> or install manually:
    <pre class="mb-0 mt-2 p-2 rounded" style="font-size:0.72rem;background:rgba(0,0,0,0.2)">install -m 0755 deploy/sbin/media-manager-svc /usr/local/sbin/media-manager-svc
install -m 0440 deploy/sudoers/media-manager /etc/sudoers.d/media-manager
visudo -cf /etc/sudoers.d/media-manager</pre>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-body py-3">
        <div class="path-text" style="font-size:0.72rem">Host</div>
        <div class="fw-semibold" id="sys-hostname"><?php echo View::e((string) ($system['hostname'] ?? '—')); ?></div>
        <div class="path-text mt-1" style="font-size:0.72rem" id="sys-os"><?php echo View::e((string) ($system['os'] ?? '')); ?></div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-body py-3">
        <div class="path-text" style="font-size:0.72rem">Uptime / load</div>
        <div class="fw-semibold" id="sys-uptime"><?php echo View::e($fmtUptime(isset($system['uptime_seconds']) ? (int) $system['uptime_seconds'] : null)); ?></div>
        <div class="path-text mt-1" style="font-size:0.72rem" id="sys-load">
          load <?php
            echo View::e(implode(' · ', array_map(
                static fn ($v) => $v === null ? '—' : number_format((float) $v, 2),
                is_array($load) ? $load : [null, null, null]
            )));
          ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-body py-3">
        <div class="path-text" style="font-size:0.72rem">Memory / disk</div>
        <div class="fw-semibold" id="sys-mem">
          <?php
          if (is_array($mem) && isset($mem['available_bytes'], $mem['total_bytes'])) {
              echo View::e($fmtBytes((int) $mem['available_bytes']) . ' free / ' . $fmtBytes((int) $mem['total_bytes']));
          } else {
              echo '—';
          }
          ?>
        </div>
        <div class="path-text mt-1" style="font-size:0.72rem" id="sys-disk">
          <?php
          if (is_array($disk) && isset($disk['free_bytes'], $disk['total_bytes'])) {
              echo View::e($fmtBytes((int) $disk['free_bytes']) . ' free / ' . $fmtBytes((int) $disk['total_bytes']));
          } else {
              echo '—';
          }
          ?>
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card h-100">
      <div class="card-body py-3">
        <div class="path-text" style="font-size:0.72rem">App workers</div>
        <div class="fw-semibold" id="sys-worker-mode">
          mode <?php echo View::e((string) ($system['worker_mode'] ?? '—')); ?>
        </div>
        <div class="path-text mt-1" style="font-size:0.72rem" id="sys-php">
          PHP <?php echo View::e((string) ($system['php_version'] ?? '')); ?>
          · poll <?php echo (int) ($system['poll_seconds'] ?? 0); ?>s
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4" id="services-cards">
  <?php
  $workers = array_values(array_filter($services, static fn ($s) => ($s['group'] ?? '') === 'workers'));
  $infra = array_values(array_filter($services, static fn ($s) => ($s['group'] ?? '') === 'infrastructure'));
  $renderCard = static function (array $s) use ($ready): void {
      $id = (string) ($s['id'] ?? '');
      $running = !empty($s['running']);
      $enabled = !empty($s['enabled']);
      $actions = is_array($s['actions'] ?? null) ? $s['actions'] : [];
      ?>
  <div class="col-lg-4 col-md-6" data-service-card="<?php echo View::e($id); ?>">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
          <div>
            <div class="fw-semibold"><?php echo View::e((string) ($s['label'] ?? $id)); ?></div>
            <div class="path-text" style="font-size:0.72rem"><code><?php echo View::e($id); ?></code></div>
          </div>
          <div class="text-end">
            <span class="svc-running-badge badge <?php echo $running ? 'bg-success' : 'bg-secondary'; ?>">
              <?php echo $running ? 'Running' : 'Stopped'; ?>
            </span>
            <div class="path-text mt-1" style="font-size:0.68rem">
              <span class="svc-enabled-label"><?php echo $enabled ? 'enabled' : View::e((string) ($s['enabled_state'] ?? '—')); ?></span>
            </div>
          </div>
        </div>
        <div class="path-text mb-3" style="font-size:0.75rem">
          <span class="svc-active-state"><?php echo View::e((string) ($s['active_state'] ?? '—')); ?></span><span class="svc-sub-state"><?php
            echo !empty($s['sub_state']) ? ' / ' . View::e((string) $s['sub_state']) : '';
          ?></span>
          · PID <span class="svc-pid"><?php echo (int) ($s['main_pid'] ?? 0) > 0 ? (int) $s['main_pid'] : '—'; ?></span>
        </div>
        <div class="d-flex flex-wrap gap-1 svc-actions">
          <?php
          $btnClass = [
              'start'   => 'btn-success',
              'stop'    => 'btn-outline-danger',
              'restart' => 'btn-primary',
              'reload'  => 'btn-outline-primary',
              'enable'  => 'btn-outline-secondary',
              'disable' => 'btn-outline-warning',
          ];
          foreach ($actions as $act):
              $act = (string) $act;
              $cls = $btnClass[$act] ?? 'btn-outline-secondary';
              $confirm = in_array($act, ['stop', 'disable', 'restart'], true);
              $warn = ($id === 'apache2' || $id === 'postgresql') && $act === 'restart';
          ?>
          <button type="button"
                  class="btn btn-sm <?php echo View::e($cls); ?> svc-action-btn"
                  data-unit="<?php echo View::e($id); ?>"
                  data-action="<?php echo View::e($act); ?>"
                  data-confirm="<?php echo $confirm || $warn ? '1' : '0'; ?>"
                  <?php echo empty($ready['ok']) ? 'disabled' : ''; ?>>
            <?php echo View::e(ucfirst($act)); ?>
          </button>
          <?php endforeach; ?>
          <button type="button"
                  class="btn btn-sm btn-outline-info svc-view-log-btn"
                  data-unit="<?php echo View::e($id); ?>">
            Logs
          </button>
        </div>
        <?php if (($s['group'] ?? '') === 'infrastructure'): ?>
        <div class="path-text mt-2" style="font-size:0.68rem">
          Infrastructure: restart/reload only (stop/disable blocked to keep the UI online).
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
      <?php
  };
  ?>
  <?php if ($services === []): ?>
  <div class="col-12">
    <div class="card">
      <div class="card-body path-text" style="font-size:0.85rem">
        Service status unavailable until the helper/sudoers are installed.
      </div>
    </div>
  </div>
  <?php else: ?>
    <?php foreach ($workers as $s) {
        $renderCard($s);
    } ?>
    <?php foreach ($infra as $s) {
        $renderCard($s);
    } ?>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <span>Journal</span>
      <select id="services-log-unit" class="form-select form-select-sm" style="width:auto;min-width:14rem">
        <?php foreach (array_merge($workers, $infra) as $s): ?>
        <option value="<?php echo View::e((string) $s['id']); ?>">
          <?php echo View::e((string) ($s['label'] ?? $s['id'])); ?>
        </option>
        <?php endforeach; ?>
        <?php if ($services === []): ?>
        <option value="media-manager-scan">Scan worker</option>
        <option value="media-manager-caption-extract">Caption extract worker</option>
        <option value="media-manager-split-audio">Split audio worker</option>
        <option value="media-manager-thumbnail">Thumbnail worker</option>
        <option value="apache2">Apache</option>
        <option value="postgresql">PostgreSQL</option>
        <?php endif; ?>
      </select>
      <span class="path-text" style="font-size:0.72rem" id="services-log-meta">—</span>
    </div>
    <div class="d-flex gap-2 align-items-center">
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" id="services-log-follow" checked>
        <label class="form-check-label path-text" for="services-log-follow" style="font-size:0.75rem">
          Follow (tail -f)
        </label>
      </div>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="services-log-clear">Clear view</button>
    </div>
  </div>
  <div class="card-body p-0">
    <pre id="services-log-view"
         class="mb-0 p-3 path-text"
         style="font-size:0.7rem;max-height:480px;overflow:auto;white-space:pre-wrap;background:var(--form-bg);min-height:240px">Waiting for journal…</pre>
  </div>
  <div class="card-footer path-text" style="font-size:0.72rem">
    Polls <code>journalctl -u &lt;unit&gt;</code> via the allowlisted helper. Auto-scrolls to the latest line unless you scroll up.
  </div>
</div>

<script src="/js/live-poll.js"></script>
<script>
(function () {
  var csrf = document.getElementById('services-csrf').value;
  var logView = document.getElementById('services-log-view');
  var logUnit = document.getElementById('services-log-unit');
  var logFollow = document.getElementById('services-log-follow');
  var logMeta = document.getElementById('services-log-meta');
  var cursor = null;
  var stickBottom = true;
  var logTimer = null;
  var statusTimer = null;
  var actionBusy = false;

  function fmtBytes(n) {
    if (n == null) return '—';
    var u = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = 0;
    var v = Number(n);
    while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
    return (i === 0 ? String(Math.round(v)) : v.toFixed(1)) + ' ' + u[i];
  }

  function fmtUptime(sec) {
    if (sec == null) return '—';
    sec = Math.floor(Number(sec));
    var d = Math.floor(sec / 86400);
    var h = Math.floor((sec % 86400) / 3600);
    var m = Math.floor((sec % 3600) / 60);
    if (d > 0) return d + 'd ' + h + 'h ' + m + 'm';
    if (h > 0) return h + 'h ' + m + 'm';
    return m + 'm';
  }

  logView.addEventListener('scroll', function () {
    stickBottom = (logView.scrollHeight - logView.scrollTop - logView.clientHeight) < 48;
  });

  function patchCard(svc) {
    var card = document.querySelector('[data-service-card="' + svc.id + '"]');
    if (!card) return;
    var badge = card.querySelector('.svc-running-badge');
    if (badge) {
      badge.textContent = svc.running ? 'Running' : 'Stopped';
      badge.className = 'svc-running-badge badge ' + (svc.running ? 'bg-success' : 'bg-secondary');
    }
    var en = card.querySelector('.svc-enabled-label');
    if (en) en.textContent = svc.enabled ? 'enabled' : (svc.enabled_state || '—');
    var a = card.querySelector('.svc-active-state');
    if (a) a.textContent = svc.active_state || '—';
    var sub = card.querySelector('.svc-sub-state');
    if (sub) sub.textContent = svc.sub_state ? (' / ' + svc.sub_state) : '';
    var pid = card.querySelector('.svc-pid');
    if (pid) pid.textContent = svc.main_pid > 0 ? String(svc.main_pid) : '—';
  }

  function patchSystem(sys) {
    if (!sys) return;
    LivePoll.setText('sys-hostname', sys.hostname || '—');
    LivePoll.setText('sys-os', sys.os || '');
    LivePoll.setText('sys-uptime', fmtUptime(sys.uptime_seconds));
    var load = (sys.loadavg || []).map(function (v) {
      return v == null ? '—' : Number(v).toFixed(2);
    }).join(' · ');
    LivePoll.setText('sys-load', 'load ' + load);
    if (sys.memory && sys.memory.total_bytes != null) {
      LivePoll.setText('sys-mem', fmtBytes(sys.memory.available_bytes) + ' free / ' + fmtBytes(sys.memory.total_bytes));
    }
    if (sys.disk && sys.disk.total_bytes != null) {
      LivePoll.setText('sys-disk', fmtBytes(sys.disk.free_bytes) + ' free / ' + fmtBytes(sys.disk.total_bytes));
    }
    LivePoll.setText('sys-worker-mode', 'mode ' + (sys.worker_mode || '—'));
    LivePoll.setText('sys-php', 'PHP ' + (sys.php_version || '') + ' · poll ' + (sys.poll_seconds || 0) + 's');
  }

  function refreshStatus() {
    fetch('/services/status', {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    })
      .then(function (r) {
        if (r.status === 401 || r.status === 403) { window.location.reload(); return null; }
        return r.json();
      })
      .then(function (data) {
        if (!data) return;
        patchSystem(data.system);
        (data.services || []).forEach(patchCard);
      })
      .catch(function () {});
  }

  function appendLogLines(lines, reset) {
    if (reset) {
      logView.textContent = lines.length ? (lines.join('\n') + '\n') : '(no journal lines)\n';
    } else if (lines && lines.length) {
      logView.textContent += lines.join('\n') + '\n';
      // Cap DOM size (~4000 lines).
      var all = logView.textContent.split('\n');
      if (all.length > 4000) {
        logView.textContent = all.slice(all.length - 3500).join('\n');
      }
    }
    if (stickBottom || (logFollow && logFollow.checked)) {
      logView.scrollTop = logView.scrollHeight;
      stickBottom = true;
    }
  }

  function pollLogs(initial) {
    if (logFollow && !logFollow.checked && !initial) return;
    var unit = logUnit.value;
    var params = new URLSearchParams();
    params.set('unit', unit);
    params.set('lines', initial || !cursor ? '150' : '80');
    if (cursor && !initial) params.set('cursor', cursor);

    fetch('/services/logs?' + params.toString(), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          if (initial) {
            logView.textContent = (data && data.error) ? data.error : 'Failed to read journal.';
          }
          logMeta.textContent = 'error';
          return;
        }
        if (data.cursor) cursor = data.cursor;
        appendLogLines(data.lines || [], !!initial || !params.has('cursor'));
        logMeta.textContent = unit + (cursor ? ' · following' : '');
      })
      .catch(function () {
        if (initial) logView.textContent = 'Failed to read journal.';
      });
  }

  function resetLogFollow() {
    cursor = null;
    stickBottom = true;
    pollLogs(true);
  }

  logUnit.addEventListener('change', resetLogFollow);
  document.getElementById('services-log-clear').addEventListener('click', function () {
    logView.textContent = '';
    stickBottom = true;
  });
  document.querySelectorAll('.svc-view-log-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      logUnit.value = btn.getAttribute('data-unit');
      resetLogFollow();
      logView.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  function runAction(unit, action, needsConfirm) {
    if (actionBusy) return;
    if (needsConfirm) {
      var msg = 'Run "' + action + '" on ' + unit + '?';
      if (unit === 'apache2' || unit === 'postgresql') {
        msg += '\n\nThis may briefly interrupt the web UI or database.';
      }
      if (action === 'stop' || action === 'disable') {
        msg += '\n\nWorkers will stop processing new jobs until started again.';
      }
      if (!window.confirm(msg)) return;
    }
    actionBusy = true;
    var body = new URLSearchParams();
    body.set('_csrf', csrf);
    body.set('unit', unit);
    body.set('action', action);
    body.set('ajax', '1');
    fetch('/services/action', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: body.toString()
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (res.j && res.j.status) patchCard(res.j.status);
        else refreshStatus();
        if (!res.j || !res.j.ok) {
          alert((res.j && res.j.message) ? res.j.message : 'Action failed.');
        }
        // Refresh logs for that unit after action.
        if (logUnit.value === unit) resetLogFollow();
      })
      .catch(function () { alert('Action request failed.'); })
      .finally(function () { actionBusy = false; });
  }

  document.querySelectorAll('.svc-action-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      runAction(
        btn.getAttribute('data-unit'),
        btn.getAttribute('data-action'),
        btn.getAttribute('data-confirm') === '1'
      );
    });
  });

  document.getElementById('services-refresh-btn').addEventListener('click', function () {
    refreshStatus();
    resetLogFollow();
  });

  statusTimer = setInterval(function () {
    if (!document.hidden) refreshStatus();
  }, 5000);
  logTimer = setInterval(function () {
    if (!document.hidden && logFollow.checked) pollLogs(false);
  }, 3000);

  pollLogs(true);
  refreshStatus();
})();
</script>
