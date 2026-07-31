<?php

declare(strict_types=1);

use MediaManager\Auth\Auth;
use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var list<array{glue_group_key: string, part_count: int, files: list<array<string, mixed>>}> $groups */
/** @var int $total */
?>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-3">
  <div>
    <h1 class="h4 mb-1" style="letter-spacing:0.03em;">Glue Queue</h1>
    <p class="mb-0" style="color:var(--text-soft);font-size:0.8rem;">
      Multipart files detected as <code>Name.ext</code> + <code>Name_1.ext</code> + <code>Name_2.ext</code> …
      Marked for ffmpeg concat before final rename. Concat execute comes later — flag and review groups here.
    </p>
  </div>
  <div class="d-flex gap-2">
    <a href="/queue?needs_glue=1&amp;status=ALL" class="btn btn-outline-secondary btn-sm">View in Catalog</a>
  </div>
</div>

<div class="mb-3 path-text" style="font-size:0.8rem">
  <?php echo number_format($total); ?> file(s) flagged · <?php echo number_format(count($groups)); ?> group(s) shown
</div>

<?php if ($groups === []): ?>
<div class="card">
  <div class="card-body text-center path-text py-5">
    No glue groups yet. Run a Scan, or select 2+ related parts in Catalog and choose
    <strong>Mark as Glue Group</strong>.
  </div>
</div>
<?php else: ?>
<?php foreach ($groups as $group): ?>
<?php
$groupKey = (string) $group['glue_group_key'];
$isManual = str_starts_with($groupKey, 'manual:');
$files = $group['files'];
$first = $files[0] ?? null;
$dir = $first !== null ? (string) ($first['original_dir'] ?? dirname((string) ($first['original_path'] ?? ''))) : '';
?>
<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-2">
    <div>
      <span class="badge <?php echo $isManual ? 'bg-info text-dark' : 'bg-secondary'; ?>">
        <?php echo $isManual ? 'Manual' : 'Auto'; ?>
      </span>
      <span class="ms-2" style="font-size:0.85rem">
        <?php echo (int) $group['part_count']; ?> parts
      </span>
      <?php if ($dir !== ''): ?>
      <div class="path-text mt-1" style="font-size:0.72rem"><?php echo View::e($dir); ?></div>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
      <a href="/queue?status=ALL&amp;glue_group=<?php echo View::e(rawurlencode($groupKey)); ?>"
         class="btn btn-outline-secondary btn-xs">Open in Catalog</a>
      <?php if (Auth::isEditor()): ?>
      <form method="post" action="/queue/clear-glue" class="d-inline"
            onsubmit="return confirm('Clear glue flags for all parts in this group?');">
        <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
        <input type="hidden" name="return" value="/glue">
        <?php foreach ($files as $f): ?>
        <input type="hidden" name="ids[]" value="<?php echo (int) $f['id']; ?>">
        <?php endforeach; ?>
        <button type="submit" class="btn btn-outline-warning btn-xs">Clear group</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle" style="font-size:0.78rem">
      <thead>
        <tr>
          <th style="width:4rem">Part</th>
          <th>File</th>
          <th>Proposed</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($files as $f): ?>
        <tr>
          <td>
            <code><?php echo View::e((string) ($f['glue_part_index'] ?? '—')); ?></code>
          </td>
          <td>
            <div class="path-filename"><?php echo View::e((string) ($f['original_filename'] ?? '')); ?></div>
            <div class="path-text" style="font-size:0.7rem">
              <?php echo View::e((string) ($f['show_abbr'] ?? '—')); ?>
              · <?php echo View::e((string) ($f['media_type_name'] ?? '—')); ?>
              · <?php echo View::duration($f['duration_seconds'] ?? null); ?>
            </div>
          </td>
          <td>
            <?php if (!empty($f['proposed_filename'])): ?>
            <div class="path-filename proposed"><?php echo View::e((string) $f['proposed_filename']); ?></div>
            <?php else: ?>
            —
            <?php endif; ?>
          </td>
          <td>
            <span class="badge bg-secondary"><?php echo View::e((string) ($f['status'] ?? '')); ?></span>
            <span class="badge badge-confidence-<?php echo View::e((string) ($f['confidence'] ?? 'UNEVALUATED')); ?> ms-1">
              <?php
              $c = (string) ($f['confidence'] ?? 'UNEVALUATED');
              echo View::e($c === 'UNEVALUATED' ? 'Unevaluated' : $c);
              ?>
            </span>
          </td>
          <td class="text-end">
            <a href="/queue?status=ALL&amp;file_id=<?php echo (int) $f['id']; ?>"
               class="btn btn-outline-secondary btn-xs">Catalog</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!empty($files[0]['glue_notes'])): ?>
  <div class="card-footer path-text py-2" style="font-size:0.72rem">
    <?php echo View::e((string) $files[0]['glue_notes']); ?>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>
