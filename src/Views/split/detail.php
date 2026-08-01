<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Services\SplitExportPolicy;
use MediaManager\Support\View;

/** @var array<string, mixed> $item */
/** @var list<array<string, mixed>> $segments */
/** @var list<array<string, mixed>> $shows */
/** @var array{prev_id: ?int, next_id: ?int, position: int, total: int} $neighbors */
/** @var string $statusFilter */
/** @var string $statusQuery */
/** @var ?string $fileDate */
/** @var ?string $fileTime */
/** @var array{mode: string, label: string, segment_seconds: int, supported: bool} $mediaInfo */

$duration = (float) ($item['duration_seconds'] ?? 0);
$jobId = (int) $item['id'];
$prevId = $neighbors['prev_id'];
$nextId = $neighbors['next_id'];
$position = (int) $neighbors['position'];
$total = (int) $neighbors['total'];
$hasSrt = !empty($item['srt_path']);
$hasCaptions = !empty($item['has_captions']);
$playMode = (string) ($mediaInfo['mode'] ?? 'proxy');
$playSupported = !empty($mediaInfo['supported']);
$segPlaySeconds = (int) ($mediaInfo['segment_seconds'] ?? 45);

$formatTc = static function (float $seconds): string {
    $seconds = max(0.0, $seconds);
    $h = (int) floor($seconds / 3600);
    $m = (int) floor(fmod($seconds, 3600) / 60);
    $s = $seconds - ($h * 3600) - ($m * 60);

    return sprintf('%02d:%02d:%06.3f', $h, $m, $s);
};

$formatAirDate = static function (string $ymd): string {
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $ymd, $m) !== 1) {
        return $ymd !== '' ? $ymd : '—';
    }

    return $m[1] . '-' . $m[2] . '-' . $m[3];
};

$formatAirTime = static function (string $hhmm): string {
    if (preg_match('/^(\d{2})(\d{2})$/', $hhmm, $m) !== 1) {
        return $hhmm !== '' ? $hhmm : '—';
    }

    return $m[1] . ':' . $m[2];
};

$segmentColors = ['#5ec8f5', '#a78bfa', '#34d399', '#fbbf24', '#fb7185', '#38bdf8', '#c084fc', '#4ade80'];
$codecLabel = trim(
    strtoupper((string) ($item['container'] ?? ''))
    . ' / '
    . strtoupper((string) ($item['codec_video'] ?? ''))
);
$exportHandleMin = SplitExportPolicy::handleMinutes();
$exportHandleSec = SplitExportPolicy::HANDLE_SECONDS;
?>

<style>
.sw-top {
  position: sticky;
  top: 0;
  z-index: 20;
  margin: -0.25rem -0.25rem 1.25rem;
  padding: 0.85rem 1rem;
  background: color-mix(in srgb, var(--panel) 92%, transparent);
  border: 1px solid var(--border-color);
  border-radius: 10px;
  backdrop-filter: blur(8px);
  box-shadow: var(--surface-shadow);
}
.sw-meta { color: var(--text-soft); font-size: 0.78rem; }
.sw-stage {
  position: relative;
  min-height: 280px;
  background: #05080d;
  border: 1px solid var(--border-color);
  border-radius: 10px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
}
.sw-stage img,
.sw-stage video {
  width: 100%;
  max-height: 360px;
  object-fit: contain;
  background: #000;
  display: block;
}
.sw-stage-empty {
  color: var(--text-soft);
  text-align: center;
  padding: 1.5rem;
  font-size: 0.82rem;
}
.sw-stage-loading {
  position: absolute;
  inset: 0;
  display: none;
  align-items: center;
  justify-content: center;
  background: rgba(5, 8, 13, 0.55);
  color: var(--text-main);
  font-size: 0.85rem;
  z-index: 2;
}
.sw-stage-loading.show { display: flex; }
.sw-transport {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  align-items: center;
  margin-top: 0.75rem;
}
.sw-playhead-tc {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  color: var(--accent);
  min-width: 7.5rem;
}
.sw-timeline {
  position: relative;
  height: 42px;
  border-radius: 8px;
  background: var(--form-bg);
  border: 1px solid var(--border-color);
  overflow: hidden;
  cursor: pointer;
  user-select: none;
}
.sw-timeline-seg {
  position: absolute;
  top: 4px;
  bottom: 4px;
  border-radius: 5px;
  opacity: 0.88;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.68rem;
  font-weight: 600;
  color: #0b1118;
  overflow: hidden;
  white-space: nowrap;
  padding: 0 4px;
  pointer-events: none;
}
.sw-timeline-playhead {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 2px;
  background: #fff;
  box-shadow: 0 0 0 1px rgba(0,0,0,0.45);
  pointer-events: none;
  z-index: 3;
}
.sw-timeline-ticks {
  display: flex;
  justify-content: space-between;
  color: var(--text-soft);
  font-size: 0.7rem;
  margin-top: 0.35rem;
}
.sw-seg-card {
  border: 1px solid var(--border-color);
  border-radius: 10px;
  background: var(--panel);
  margin-bottom: 0.75rem;
  overflow: hidden;
  cursor: pointer;
}
.sw-seg-card.active {
  border-color: var(--accent);
  box-shadow: 0 0 0 1px color-mix(in srgb, var(--accent) 35%, transparent);
}
.sw-seg-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  padding: 0.55rem 0.75rem;
  background: var(--card-header-bg);
  border-bottom: 1px solid var(--border-color);
}
.sw-seg-swatch {
  width: 10px;
  height: 10px;
  border-radius: 999px;
  flex: 0 0 auto;
}
.sw-seg-body { padding: 0.75rem; }
.sw-air {
  font-variant-numeric: tabular-nums;
  font-size: 0.82rem;
  color: var(--accent);
}
.sw-export-callout {
  border: 1px solid color-mix(in srgb, var(--accent) 40%, var(--border-color));
  background: var(--info-soft);
  border-radius: 10px;
  padding: 0.75rem 0.9rem;
  margin-bottom: 0.85rem;
  font-size: 0.82rem;
}
.sw-export-callout strong { color: var(--text-main); }
.sw-export-range {
  font-variant-numeric: tabular-nums;
  font-size: 0.78rem;
  color: var(--text-soft);
  margin-top: 0.35rem;
}
.sw-export-range strong { color: var(--text-main); font-weight: 600; }
.sw-seg-list {
  max-height: min(70vh, 820px);
  overflow: auto;
  padding-right: 0.15rem;
}
.sw-actions-bar {
  position: sticky;
  bottom: 0;
  z-index: 15;
  margin-top: 1rem;
  padding: 0.75rem 1rem;
  background: color-mix(in srgb, var(--panel) 94%, transparent);
  border: 1px solid var(--border-color);
  border-radius: 10px;
  backdrop-filter: blur(8px);
}
</style>

<div class="sw-top">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
    <div class="min-w-0">
      <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <h1 class="h5 mb-0" style="letter-spacing:0.03em;">Split Workbench</h1>
        <span class="badge text-bg-secondary">#<?php echo $jobId; ?></span>
        <?php echo View::statusBadge((string) $item['status']); ?>
        <?php if ($playMode === 'fast'): ?>
        <span class="badge" style="background:rgba(34,197,94,0.2);color:var(--badge-high)">Fast path</span>
        <?php elseif ($playMode === 'proxy'): ?>
        <span class="badge" style="background:rgba(94,200,245,0.18);color:var(--accent)">Proxy path</span>
        <?php else: ?>
        <span class="badge text-bg-danger">Unsupported</span>
        <?php endif; ?>
        <?php if ($hasSrt): ?>
        <span class="badge" style="background:rgba(34,197,94,0.2);color:var(--badge-high)">CC SRT</span>
        <?php elseif ($hasCaptions): ?>
        <span class="badge" style="background:rgba(251,146,60,0.2);color:#fb923c">CC</span>
        <?php endif; ?>
      </div>
      <div class="path-filename text-truncate" title="<?php echo View::e($item['original_filename'] ?? ''); ?>">
        <?php echo View::e($item['original_filename'] ?? ''); ?>
      </div>
      <div class="path-text text-truncate" title="<?php echo View::e($item['original_path'] ?? ''); ?>">
        <?php echo View::e($item['original_path'] ?? ''); ?>
      </div>
      <?php echo View::assetIdBlock($item); ?>
      <div class="sw-meta mt-1">
        Duration <strong class="text-body"><?php echo View::duration($item['duration_seconds'] ?? null); ?></strong>
        · <?php echo View::e($codecLabel !== ' / ' ? $codecLabel : 'No codec meta'); ?>
        · File clock
        <strong class="text-body">
          <?php
          $clockDate = preg_replace('/\D/', '', (string) ($fileDate ?? '')) ?? '';
          $clockTime = preg_replace('/\D/', '', (string) ($fileTime ?? '')) ?? '';
          echo View::e(
              ($clockDate !== '' ? $formatAirDate($clockDate) : '—')
              . ' '
              . ($clockTime !== '' ? $formatAirTime(substr($clockTime, 0, 4)) : '—')
          );
          ?>
        </strong>
      </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-2">
      <a href="/split<?php echo View::e($statusQuery); ?>" class="btn btn-outline-secondary btn-sm">Queue</a>
      <?php if ($prevId !== null): ?>
      <a href="/split/<?php echo $prevId; ?><?php echo View::e($statusQuery); ?>"
         class="btn btn-outline-secondary btn-sm" id="sw-prev" title="Previous in queue">← Back</a>
      <?php else: ?>
      <button type="button" class="btn btn-outline-secondary btn-sm" disabled>← Back</button>
      <?php endif; ?>
      <span class="sw-meta px-1 text-nowrap">
        <?php echo (int) $position; ?> / <?php echo (int) $total; ?>
        <?php if ($statusFilter !== ''): ?>
          <span class="ms-1">(<?php echo View::e($statusFilter); ?>)</span>
        <?php endif; ?>
      </span>
      <?php if ($nextId !== null): ?>
      <a href="/split/<?php echo $nextId; ?><?php echo View::e($statusQuery); ?>"
         class="btn btn-outline-secondary btn-sm" id="sw-next" title="Next in queue">Next →</a>
      <?php else: ?>
      <button type="button" class="btn btn-outline-secondary btn-sm" disabled>Next →</button>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if (!empty($item['split_notes'])): ?>
<div class="alert alert-secondary py-2 px-3 mb-3" style="font-size:0.82rem">
  <?php echo View::e($item['split_notes']); ?>
</div>
<?php endif; ?>

<form method="post" action="/split/update" id="split-form"
      data-file-date="<?php echo View::e($fileDate ?? ''); ?>"
      data-file-time="<?php echo View::e($fileTime ?? ''); ?>"
      data-duration="<?php echo View::e((string) $duration); ?>"
      data-job-id="<?php echo $jobId; ?>"
      data-play-mode="<?php echo View::e($playMode); ?>"
      data-play-supported="<?php echo $playSupported ? '1' : '0'; ?>"
      data-segment-seconds="<?php echo (int) $segPlaySeconds; ?>">
  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
  <input type="hidden" name="id" value="<?php echo $jobId; ?>">
  <input type="hidden" name="status_filter" value="<?php echo View::e($statusFilter); ?>">
  <input type="hidden" name="next_id" value="<?php echo $nextId !== null ? (int) $nextId : 0; ?>">
  <input type="hidden" name="redirect" id="sw-redirect" value="">

  <div class="row g-4">
    <div class="col-xl-5">
      <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span>Preview / Timeline</span>
          <span class="sw-meta"><?php echo View::e($mediaInfo['label'] ?? ''); ?> · <?php echo (int) $segPlaySeconds; ?>s play window</span>
        </div>
        <div class="card-body">
          <div class="sw-stage mb-2" id="sw-stage">
            <div class="sw-stage-empty" id="sw-stage-empty">
              Click the timeline to scrub. Frame peeks work for MP4, TS, MXF, and related codecs.
            </div>
            <img id="sw-frame" class="d-none" alt="Scrub frame">
            <video id="sw-video" class="d-none" controls playsinline></video>
            <div class="sw-stage-loading" id="sw-loading">Loading…</div>
          </div>

          <div class="sw-transport">
            <button type="button" class="btn btn-primary btn-sm" id="sw-play" <?php echo $playSupported ? '' : 'disabled'; ?>>Play</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="sw-pause" disabled>Pause</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="sw-set-in" title="Set mark in (I)">Set In</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="sw-set-out" title="Set mark out (O)">Set Out</button>
            <span class="sw-playhead-tc" id="sw-playhead-tc">00:00:00.000</span>
            <span class="sw-meta" id="sw-play-status"></span>
          </div>

          <div class="sw-timeline mt-3" id="sw-timeline" aria-label="Segment timeline" title="Click to scrub">
            <div id="sw-timeline-segs">
            <?php if ($duration > 0): ?>
            <?php foreach ($segments as $i => $seg):
                $start = (float) ($seg['start'] ?? 0);
                $end = (float) ($seg['end'] ?? 0);
                if ($end <= $start) {
                    continue;
                }
                $left = max(0.0, min(100.0, ($start / $duration) * 100));
                $width = max(0.4, min(100.0 - $left, (($end - $start) / $duration) * 100));
                $color = $segmentColors[$i % count($segmentColors)];
                $label = trim((string) ($seg['label'] ?? ''));
                if ($label === '') {
                    $label = 'Seg ' . ($i + 1);
                }
            ?>
            <div class="sw-timeline-seg"
                 data-seg-index="<?php echo (int) $i; ?>"
                 style="left:<?php echo View::e((string) round($left, 2)); ?>%;width:<?php echo View::e((string) round($width, 2)); ?>%;background:<?php echo View::e($color); ?>"
                 title="<?php echo View::e($label . ' · ' . $formatTc($start) . ' – ' . $formatTc($end)); ?>">
              <?php echo View::e($label); ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            </div>
            <div class="sw-timeline-playhead" id="sw-playhead" style="left:0%"></div>
          </div>
          <div class="sw-timeline-ticks">
            <span>0:00</span>
            <span><?php echo View::duration($duration > 0 ? $duration / 2 : null); ?></span>
            <span><?php echo View::duration($duration > 0 ? $duration : null); ?></span>
          </div>
          <div class="sw-meta mt-2">
            Scrub = frame peek · Play = <?php echo $playMode === 'fast' ? 'stream-copy segment when possible' : 'H.264 proxy segment'; ?>.
            Keys: I / O marks · Space play/pause · ← → queue
          </div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label">Notes</label>
              <textarea name="notes" class="form-control" rows="3"><?php echo View::e($item['notes'] ?? ''); ?></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach (['PENDING', 'IN_PROGRESS', 'DONE', 'FAILED'] as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo ($item['status'] ?? '') === $st ? 'selected' : ''; ?>>
                  <?php echo $st; ?>
                </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" style="color:var(--text-soft)">
                Mark DONE when show marks look right. Export will add ±<?php echo (int) $exportHandleMin; ?> min handles later.
              </div>
            </div>
          </div>
          <?php if (!empty($item['proposed_filename'])): ?>
          <hr style="border-color:var(--border-color)">
          <div class="path-text">Source file proposed target (not per-segment yet)</div>
          <div class="path-filename proposed"><?php echo View::e($item['proposed_filename']); ?></div>
          <div class="path-text proposed"><?php echo View::e($item['proposed_dir']); ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-xl-7">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div>
          <strong>Segments</strong>
          <span class="sw-meta ms-2">Mark the show only · Active card receives Set In / Set Out</span>
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="add-segment">Add Segment</button>
      </div>

      <div class="sw-export-callout" role="note">
        <strong>Export handles (±<?php echo (int) $exportHandleMin; ?> min)</strong>
        — Set Mark In / Mark Out on the <em>show itself</em>.
        When this clip is exported later, the system will include up to
        <strong><?php echo (int) $exportHandleMin; ?> minutes before Mark In</strong>
        and <strong><?php echo (int) $exportHandleMin; ?> minutes after Mark Out</strong>
        when media is available (clamped to the file edges). Do not pad the marks yourself.
      </div>

      <div class="sw-seg-list" id="segments-list">
        <?php
        $rows = $segments;
        if ($rows === []) {
            $rows = [[
                'start'   => 0.0,
                'end'     => $duration > 0 ? $duration : 0.0,
                'show_id' => $item['show_id'] ?? null,
                'label'   => '',
            ]];
        }
        foreach ($rows as $i => $seg):
            $start = (float) ($seg['start'] ?? 0);
            $end = (float) ($seg['end'] ?? 0);
            $airDate = (string) ($seg['air_date'] ?? '');
            $airTime = (string) ($seg['air_time'] ?? '');
            $color = $segmentColors[$i % count($segmentColors)];
            $segDur = max(0.0, $end - $start);
            $export = SplitExportPolicy::exportRange($start, $end, $duration > 0 ? $duration : null);
            $padBeforeLabel = $export['pad_before'] >= $exportHandleSec - 0.5
                ? $exportHandleMin . ' min'
                : View::duration($export['pad_before']);
            $padAfterLabel = $export['pad_after'] >= $exportHandleSec - 0.5
                ? $exportHandleMin . ' min'
                : View::duration($export['pad_after']);
        ?>
        <div class="sw-seg-card segment-row<?php echo $i === 0 ? ' active' : ''; ?>" data-index="<?php echo (int) $i; ?>">
          <div class="sw-seg-head">
            <div class="d-flex align-items-center gap-2 min-w-0">
              <span class="sw-seg-swatch" style="background:<?php echo View::e($color); ?>"></span>
              <strong class="seg-title text-truncate">Segment <?php echo (int) ($i + 1); ?></strong>
              <span class="sw-meta seg-dur"><?php echo View::duration($segDur); ?></span>
            </div>
            <button type="button" class="btn btn-outline-danger btn-xs remove-segment" title="Remove segment">&times;</button>
          </div>
          <div class="sw-seg-body">
            <div class="row g-2 align-items-end">
              <div class="col-sm-3">
                <label class="form-label mb-1">Mark In <span class="sw-meta">(show)</span></label>
                <input type="text" class="form-control form-control-sm seg-tc-start" inputmode="decimal"
                       value="<?php echo View::e($formatTc($start)); ?>" placeholder="00:00:00.000" autocomplete="off">
                <input type="hidden" name="segment_start[]" class="seg-start" value="<?php echo View::e((string) $start); ?>">
              </div>
              <div class="col-sm-3">
                <label class="form-label mb-1">Mark Out <span class="sw-meta">(show)</span></label>
                <input type="text" class="form-control form-control-sm seg-tc-end" inputmode="decimal"
                       value="<?php echo View::e($formatTc($end)); ?>" placeholder="00:00:00.000" autocomplete="off">
                <input type="hidden" name="segment_end[]" class="seg-end" value="<?php echo View::e((string) $end); ?>">
              </div>
              <div class="col-sm-3">
                <label class="form-label mb-1">Show</label>
                <select name="segment_show_id[]" class="form-select form-select-sm seg-show">
                  <option value="">—</option>
                  <?php foreach ($shows as $show): ?>
                  <option value="<?php echo (int) $show['id']; ?>"
                    <?php echo (int) ($seg['show_id'] ?? 0) === (int) $show['id'] ? 'selected' : ''; ?>>
                    <?php echo View::e($show['abbreviation']); ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-3">
                <label class="form-label mb-1">Label</label>
                <input type="text" name="segment_label[]" class="form-control form-control-sm seg-label"
                       value="<?php echo View::e($seg['label'] ?? ''); ?>" placeholder="Optional label">
              </div>
            </div>
            <div class="d-flex flex-wrap justify-content-between gap-2 mt-2">
              <div class="sw-air seg-air">
                Air
                <strong class="seg-air-date"><?php echo View::e($airDate !== '' ? $formatAirDate($airDate) : '—'); ?></strong>
                <strong class="seg-air-time"><?php echo View::e($airTime !== '' ? $formatAirTime($airTime) : '—'); ?></strong>
                <span class="sw-meta ms-1">(from file clock + mark in)</span>
              </div>
            </div>
            <div class="sw-export-range seg-export">
              Export will cut
              <strong class="seg-export-start"><?php echo View::e($formatTc((float) $export['export_start'])); ?></strong>
              →
              <strong class="seg-export-end"><?php echo View::e($formatTc((float) $export['export_end'])); ?></strong>
              <span class="ms-1">(−<span class="seg-pad-before"><?php echo View::e($padBeforeLabel); ?></span>
              / +<span class="seg-pad-after"><?php echo View::e($padAfterLabel); ?></span> handles)</span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="sw-actions-bar d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div class="d-flex flex-wrap gap-2">
      <button type="submit" class="btn btn-primary btn-sm" id="sw-save">Save</button>
      <button type="submit" class="btn btn-outline-primary btn-sm" id="sw-save-next"
              <?php echo $nextId === null ? 'disabled' : ''; ?>>
        Save &amp; Next →
      </button>
    </div>
    <small class="path-text">
      Created by <?php echo View::e($item['created_by_email'] ?? ''); ?>
    </small>
  </div>
</form>

<div class="d-flex flex-wrap gap-2 mt-3 mb-2">
  <form method="post" action="/split/suggest-captions"
        onsubmit="return confirm('Replace segment rows with caption-based suggestions (≥5 min silence gaps near hour boundaries)? Unsaved edits will be lost.');">
    <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
    <input type="hidden" name="id" value="<?php echo $jobId; ?>">
    <input type="hidden" name="status_filter" value="<?php echo View::e($statusFilter); ?>">
    <button type="submit" class="btn btn-outline-info btn-sm" <?php echo $hasSrt ? '' : 'disabled'; ?>>
      Suggest from captions
    </button>
  </form>
  <form method="post" action="/split/delete"
        onsubmit="return confirm('Remove this split job from the queue?');">
    <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
    <input type="hidden" name="id" value="<?php echo $jobId; ?>">
    <button type="submit" class="btn btn-outline-danger btn-sm">Delete Split Job</button>
  </form>
  <?php if (!$hasSrt): ?>
  <span class="sw-meta align-self-center">Extract SRT from Catalog to enable caption suggest.</span>
  <?php endif; ?>
</div>

<template id="segment-row-template">
  <div class="sw-seg-card segment-row" data-index="0">
    <div class="sw-seg-head">
      <div class="d-flex align-items-center gap-2 min-w-0">
        <span class="sw-seg-swatch" style="background:#5ec8f5"></span>
        <strong class="seg-title text-truncate">Segment 1</strong>
        <span class="sw-meta seg-dur">—</span>
      </div>
      <button type="button" class="btn btn-outline-danger btn-xs remove-segment" title="Remove segment">&times;</button>
    </div>
    <div class="sw-seg-body">
      <div class="row g-2 align-items-end">
        <div class="col-sm-3">
          <label class="form-label mb-1">Mark In <span class="sw-meta">(show)</span></label>
          <input type="text" class="form-control form-control-sm seg-tc-start" inputmode="decimal"
                 value="00:00:00.000" placeholder="00:00:00.000" autocomplete="off">
          <input type="hidden" name="segment_start[]" class="seg-start" value="0">
        </div>
        <div class="col-sm-3">
          <label class="form-label mb-1">Mark Out <span class="sw-meta">(show)</span></label>
          <input type="text" class="form-control form-control-sm seg-tc-end" inputmode="decimal"
                 value="00:00:00.000" placeholder="00:00:00.000" autocomplete="off">
          <input type="hidden" name="segment_end[]" class="seg-end" value="0">
        </div>
        <div class="col-sm-3">
          <label class="form-label mb-1">Show</label>
          <select name="segment_show_id[]" class="form-select form-select-sm seg-show">
            <option value="">—</option>
            <?php foreach ($shows as $show): ?>
            <option value="<?php echo (int) $show['id']; ?>"><?php echo View::e($show['abbreviation']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-3">
          <label class="form-label mb-1">Label</label>
          <input type="text" name="segment_label[]" class="form-control form-control-sm seg-label"
                 value="" placeholder="Optional label">
        </div>
      </div>
      <div class="d-flex flex-wrap justify-content-between gap-2 mt-2">
        <div class="sw-air seg-air">
          Air
          <strong class="seg-air-date">—</strong>
          <strong class="seg-air-time">—</strong>
          <span class="sw-meta ms-1">(from file clock + mark in)</span>
        </div>
      </div>
      <div class="sw-export-range seg-export">
        Export will cut
        <strong class="seg-export-start">—</strong>
        →
        <strong class="seg-export-end">—</strong>
        <span class="ms-1">(−<span class="seg-pad-before">—</span>
        / +<span class="seg-pad-after">—</span> handles)</span>
      </div>
    </div>
  </div>
</template>

<script>
(function () {
    var form = document.getElementById('split-form');
    var list = document.getElementById('segments-list');
    var tpl = document.getElementById('segment-row-template');
    var redirectInput = document.getElementById('sw-redirect');
    var colors = <?php echo json_encode($segmentColors, JSON_THROW_ON_ERROR); ?>;
    var fileDate = (form.getAttribute('data-file-date') || '').replace(/\D/g, '');
    var fileTime = (form.getAttribute('data-file-time') || '').replace(/\D/g, '').slice(0, 4);
    var duration = parseFloat(form.getAttribute('data-duration') || '0') || 0;
    var jobId = form.getAttribute('data-job-id') || '';
    var playSupported = form.getAttribute('data-play-supported') === '1';
    var exportHandleSec = <?php echo (int) $exportHandleSec; ?>;
    var exportHandleMin = <?php echo (int) $exportHandleMin; ?>;

    var frameEl = document.getElementById('sw-frame');
    var videoEl = document.getElementById('sw-video');
    var emptyEl = document.getElementById('sw-stage-empty');
    var loadingEl = document.getElementById('sw-loading');
    var timeline = document.getElementById('sw-timeline');
    var timelineSegs = document.getElementById('sw-timeline-segs');
    var playhead = document.getElementById('sw-playhead');
    var playheadTc = document.getElementById('sw-playhead-tc');
    var playStatus = document.getElementById('sw-play-status');
    var btnPlay = document.getElementById('sw-play');
    var btnPause = document.getElementById('sw-pause');
    var btnSetIn = document.getElementById('sw-set-in');
    var btnSetOut = document.getElementById('sw-set-out');

    var playheadSec = 0;
    var segmentStart = 0;
    var scrubTimer = null;
    var frameReq = 0;

    function pad2(n) { return String(n).padStart(2, '0'); }

    function secondsToTc(sec) {
        sec = Math.max(0, Number(sec) || 0);
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec - h * 3600 - m * 60;
        return pad2(h) + ':' + pad2(m) + ':' + (s < 10 ? '0' : '') + s.toFixed(3);
    }

    function tcToSeconds(tc) {
        var raw = String(tc || '').trim().replace(',', '.');
        if (/^\d+(\.\d+)?$/.test(raw)) return parseFloat(raw);
        var parts = raw.split(':');
        if (parts.length === 2) parts.unshift('0');
        if (parts.length !== 3) return null;
        var h = parseInt(parts[0], 10);
        var m = parseInt(parts[1], 10);
        var s = parseFloat(parts[2]);
        if ([h, m, s].some(function (v) { return Number.isNaN(v); })) return null;
        return (h * 3600) + (m * 60) + s;
    }

    function formatAirDate(ymd) {
        if (!/^\d{8}$/.test(ymd)) return '—';
        return ymd.slice(0, 4) + '-' + ymd.slice(4, 6) + '-' + ymd.slice(6, 8);
    }

    function formatAirTime(hhmm) {
        if (!/^\d{4}$/.test(hhmm)) return '—';
        return hhmm.slice(0, 2) + ':' + hhmm.slice(2, 4);
    }

    function deriveAir(offsetSec) {
        if (fileDate.length !== 8 || fileTime.length !== 4) {
            return { date: '—', time: '—' };
        }
        var startMin = (parseInt(fileTime.slice(0, 2), 10) * 60) + parseInt(fileTime.slice(2, 4), 10);
        var offsetMin = Math.floor(Math.max(0, offsetSec) / 60);
        var total = startMin + offsetMin;
        var dayAdd = Math.floor(total / (24 * 60));
        var tod = total % (24 * 60);
        var y = parseInt(fileDate.slice(0, 4), 10);
        var mo = parseInt(fileDate.slice(4, 6), 10) - 1;
        var d = parseInt(fileDate.slice(6, 8), 10);
        var dt = new Date(Date.UTC(y, mo, d + dayAdd));
        var ymd = String(dt.getUTCFullYear()) + pad2(dt.getUTCMonth() + 1) + pad2(dt.getUTCDate());
        var hhmm = pad2(Math.floor(tod / 60)) + pad2(tod % 60);
        return { date: formatAirDate(ymd), time: formatAirTime(hhmm) };
    }

    function formatDur(sec) {
        sec = Math.max(0, Math.floor(Number(sec) || 0));
        var h = Math.floor(sec / 3600);
        var m = Math.floor((sec % 3600) / 60);
        var s = sec % 60;
        if (h > 0) return h + ':' + pad2(m) + ':' + pad2(s);
        return m + ':' + pad2(s);
    }

    function setLoading(on, msg) {
        loadingEl.textContent = msg || 'Loading…';
        loadingEl.classList.toggle('show', !!on);
    }

    function showFrameMode() {
        videoEl.pause();
        videoEl.classList.add('d-none');
        videoEl.removeAttribute('src');
        videoEl.load();
        frameEl.classList.remove('d-none');
        emptyEl.classList.add('d-none');
        btnPause.disabled = true;
    }

    function showVideoMode() {
        frameEl.classList.add('d-none');
        emptyEl.classList.add('d-none');
        videoEl.classList.remove('d-none');
        btnPause.disabled = false;
    }

    function updatePlayheadUi() {
        playheadTc.textContent = secondsToTc(playheadSec);
        var pct = duration > 0 ? Math.max(0, Math.min(100, (playheadSec / duration) * 100)) : 0;
        playhead.style.left = pct + '%';
    }

    function activeRow() {
        return list.querySelector('.segment-row.active') || list.querySelector('.segment-row');
    }

    function setActiveRow(row) {
        list.querySelectorAll('.segment-row').forEach(function (r) {
            r.classList.toggle('active', r === row);
        });
    }

    function padLabel(sec) {
        if (sec >= exportHandleSec - 0.5) {
            return exportHandleMin + ' min';
        }
        return formatDur(sec);
    }

    function exportRange(markIn, markOut) {
        var s = Math.max(0, markIn);
        var e = Math.max(s, markOut);
        var exportStart = Math.max(0, s - exportHandleSec);
        var exportEnd = e + exportHandleSec;
        if (duration > 0) {
            exportEnd = Math.min(duration, exportEnd);
        }
        return {
            exportStart: exportStart,
            exportEnd: exportEnd,
            padBefore: s - exportStart,
            padAfter: exportEnd - e
        };
    }

    function syncRow(row) {
        var startTc = row.querySelector('.seg-tc-start');
        var endTc = row.querySelector('.seg-tc-end');
        var startH = row.querySelector('.seg-start');
        var endH = row.querySelector('.seg-end');
        var start = tcToSeconds(startTc.value);
        var end = tcToSeconds(endTc.value);
        if (start !== null) {
            startH.value = String(start);
            startTc.value = secondsToTc(start);
        }
        if (end !== null) {
            endH.value = String(end);
            endTc.value = secondsToTc(end);
        }
        var s = parseFloat(startH.value) || 0;
        var e = parseFloat(endH.value) || 0;
        row.querySelector('.seg-dur').textContent = formatDur(e - s);
        var air = deriveAir(s);
        row.querySelector('.seg-air-date').textContent = air.date;
        row.querySelector('.seg-air-time').textContent = air.time;
        var exp = exportRange(s, e);
        var expStartEl = row.querySelector('.seg-export-start');
        var expEndEl = row.querySelector('.seg-export-end');
        var padBeforeEl = row.querySelector('.seg-pad-before');
        var padAfterEl = row.querySelector('.seg-pad-after');
        if (expStartEl) expStartEl.textContent = secondsToTc(exp.exportStart);
        if (expEndEl) expEndEl.textContent = secondsToTc(exp.exportEnd);
        if (padBeforeEl) padBeforeEl.textContent = padLabel(exp.padBefore);
        if (padAfterEl) padAfterEl.textContent = padLabel(exp.padAfter);
    }

    function renumber() {
        var rows = list.querySelectorAll('.segment-row');
        rows.forEach(function (row, i) {
            row.dataset.index = String(i);
            row.querySelector('.seg-title').textContent = 'Segment ' + (i + 1);
            var swatch = row.querySelector('.sw-seg-swatch');
            if (swatch) swatch.style.background = colors[i % colors.length];
        });
        rebuildTimeline();
    }

    function rebuildTimeline() {
        if (!timelineSegs || duration <= 0) return;
        timelineSegs.innerHTML = '';
        list.querySelectorAll('.segment-row').forEach(function (row, i) {
            var s = parseFloat(row.querySelector('.seg-start').value) || 0;
            var e = parseFloat(row.querySelector('.seg-end').value) || 0;
            if (e <= s) return;
            var left = Math.max(0, Math.min(100, (s / duration) * 100));
            var width = Math.max(0.4, Math.min(100 - left, ((e - s) / duration) * 100));
            var label = (row.querySelector('.seg-label').value || '').trim() || ('Seg ' + (i + 1));
            var el = document.createElement('div');
            el.className = 'sw-timeline-seg';
            el.style.left = left + '%';
            el.style.width = width + '%';
            el.style.background = colors[i % colors.length];
            el.title = label + ' · ' + secondsToTc(s) + ' – ' + secondsToTc(e);
            el.textContent = label;
            timelineSegs.appendChild(el);
        });
    }

    function loadFrame(at, immediate) {
        if (!playSupported && form.getAttribute('data-play-mode') === 'unsupported') {
            playStatus.textContent = 'WMV not supported';
            return;
        }
        playheadSec = Math.max(0, duration > 0 ? Math.min(at, duration) : at);
        updatePlayheadUi();
        showFrameMode();

        var run = function () {
            var req = ++frameReq;
            setLoading(true, 'Extracting frame…');
            var url = '/split/media/' + jobId + '/frame?t=' + encodeURIComponent(playheadSec.toFixed(3)) + '&_=' + Date.now();
            var img = new Image();
            img.onload = function () {
                if (req !== frameReq) return;
                frameEl.src = url;
                emptyEl.classList.add('d-none');
                frameEl.classList.remove('d-none');
                setLoading(false);
                playStatus.textContent = 'Frame @ ' + secondsToTc(playheadSec);
            };
            img.onerror = function () {
                if (req !== frameReq) return;
                setLoading(false);
                playStatus.textContent = 'Frame failed';
            };
            img.src = url;
        };

        if (immediate) {
            if (scrubTimer) clearTimeout(scrubTimer);
            run();
            return;
        }
        if (scrubTimer) clearTimeout(scrubTimer);
        scrubTimer = setTimeout(run, 120);
    }

    function timelineTimeFromEvent(e) {
        var rect = timeline.getBoundingClientRect();
        if (rect.width <= 0 || duration <= 0) return 0;
        var x = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        return x * duration;
    }

    timeline.addEventListener('click', function (e) {
        loadFrame(timelineTimeFromEvent(e), true);
    });

    timeline.addEventListener('mousemove', function (e) {
        if (e.buttons !== 1) return;
        loadFrame(timelineTimeFromEvent(e), false);
    });

    btnSetIn.addEventListener('click', function () {
        var row = activeRow();
        if (!row) return;
        row.querySelector('.seg-tc-start').value = secondsToTc(playheadSec);
        syncRow(row);
        rebuildTimeline();
        playStatus.textContent = 'Mark In set';
    });

    btnSetOut.addEventListener('click', function () {
        var row = activeRow();
        if (!row) return;
        row.querySelector('.seg-tc-end').value = secondsToTc(playheadSec);
        syncRow(row);
        rebuildTimeline();
        playStatus.textContent = 'Mark Out set';
    });

    btnPlay.addEventListener('click', function () {
        if (!playSupported) return;
        segmentStart = Math.floor(playheadSec);
        setLoading(true, playModeLabel() + ' segment…');
        playStatus.textContent = 'Building play window…';
        var url = '/split/media/' + jobId + '/play?t=' + encodeURIComponent(String(segmentStart)) + '&_=' + Date.now();
        showVideoMode();
        videoEl.onloadeddata = function () {
            setLoading(false);
            playStatus.textContent = 'Playing from ' + secondsToTc(segmentStart);
            videoEl.play().catch(function () {
                playStatus.textContent = 'Play blocked — click Play again';
            });
        };
        videoEl.onerror = function () {
            setLoading(false);
            playStatus.textContent = 'Play segment failed';
            showFrameMode();
        };
        videoEl.src = url;
        videoEl.load();
    });

    function playModeLabel() {
        var m = form.getAttribute('data-play-mode');
        return m === 'fast' ? 'Fast-path' : 'Proxy';
    }

    btnPause.addEventListener('click', function () {
        videoEl.pause();
    });

    videoEl.addEventListener('timeupdate', function () {
        if (videoEl.classList.contains('d-none')) return;
        playheadSec = segmentStart + (videoEl.currentTime || 0);
        if (duration > 0) playheadSec = Math.min(playheadSec, duration);
        updatePlayheadUi();
    });

    videoEl.addEventListener('pause', function () {
        btnPause.disabled = true;
        btnPlay.disabled = !playSupported;
    });

    videoEl.addEventListener('play', function () {
        btnPause.disabled = false;
    });

    videoEl.addEventListener('ended', function () {
        playStatus.textContent = 'Segment ended — scrub or Play again to continue';
        loadFrame(playheadSec, true);
    });

    document.getElementById('add-segment').addEventListener('click', function () {
        list.appendChild(tpl.content.cloneNode(true));
        renumber();
        var rows = list.querySelectorAll('.segment-row');
        var row = rows[rows.length - 1];
        syncRow(row);
        setActiveRow(row);
    });

    list.addEventListener('click', function (e) {
        var row = e.target.closest('.segment-row');
        if (!row) return;
        if (e.target.classList.contains('remove-segment')) {
            var rows = list.querySelectorAll('.segment-row');
            if (rows.length <= 1) return;
            row.remove();
            renumber();
            setActiveRow(list.querySelector('.segment-row'));
            return;
        }
        setActiveRow(row);
    });

    list.addEventListener('change', function (e) {
        var row = e.target.closest('.segment-row');
        if (!row) return;
        if (e.target.classList.contains('seg-tc-start') || e.target.classList.contains('seg-tc-end') || e.target.classList.contains('seg-label')) {
            syncRow(row);
            rebuildTimeline();
        }
    });

    list.addEventListener('blur', function (e) {
        var row = e.target.closest('.segment-row');
        if (!row) return;
        if (e.target.classList.contains('seg-tc-start') || e.target.classList.contains('seg-tc-end')) {
            syncRow(row);
            rebuildTimeline();
        }
    }, true);

    form.addEventListener('submit', function () {
        list.querySelectorAll('.segment-row').forEach(syncRow);
    });

    document.getElementById('sw-save').addEventListener('click', function () {
        redirectInput.value = '';
    });
    var saveNext = document.getElementById('sw-save-next');
    if (saveNext) {
        saveNext.addEventListener('click', function () {
            redirectInput.value = 'next';
        });
    }

    document.addEventListener('keydown', function (e) {
        var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
        if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
        if (e.key === 'ArrowLeft' && !e.shiftKey) {
            var prev = document.getElementById('sw-prev');
            if (prev) { e.preventDefault(); prev.click(); }
        } else if (e.key === 'ArrowRight' && !e.shiftKey) {
            var next = document.getElementById('sw-next');
            if (next) { e.preventDefault(); next.click(); }
        } else if (e.key === 'i' || e.key === 'I') {
            e.preventDefault();
            btnSetIn.click();
        } else if (e.key === 'o' || e.key === 'O') {
            e.preventDefault();
            btnSetOut.click();
        } else if (e.key === ' ' || e.code === 'Space') {
            e.preventDefault();
            if (!videoEl.classList.contains('d-none') && !videoEl.paused) {
                btnPause.click();
            } else {
                btnPlay.click();
            }
        } else if (e.key === 'j' || e.key === 'J') {
            e.preventDefault();
            loadFrame(Math.max(0, playheadSec - 5), true);
        } else if (e.key === 'l' || e.key === 'L') {
            e.preventDefault();
            loadFrame(playheadSec + 5, true);
        } else if (e.key === 'k' || e.key === 'K') {
            e.preventDefault();
            loadFrame(playheadSec, true);
        }
    });

    list.querySelectorAll('.segment-row').forEach(syncRow);
    renumber();
    updatePlayheadUi();
    if (duration > 0 && playSupported) {
        loadFrame(Math.min(30, duration * 0.05), true);
    }
})();
</script>
