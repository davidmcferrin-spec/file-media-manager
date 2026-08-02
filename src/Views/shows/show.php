<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\ScheduleEntryParser;
use MediaManager\Support\View;

/** @var array<string, mixed> $show */
/** @var list<string> $aliases */
/** @var list<array<string, mixed>> $slots */
/** @var list<array<string, mixed>> $eras */
/** @var list<array<string, mixed>> $allShows */
/** @var array<string, mixed>|null $editSlot */
$showId = (int) $show['id'];
$showsTab = 'shows';
$aliasText = implode("\n", array_map('strval', $aliases));
$slotForm = $editSlot ?? [
    'title' => (string) $show['canonical_name'],
    'hour_start_et' => '09:00',
    'hour_end_et' => '10:00',
    'days_of_week' => 31,
    'effective_from' => date('Y-m-d'),
    'effective_to' => '',
    'era_name' => '',
    'broadcast_era_id' => null,
    'notes' => '',
    'active' => true,
];
$daysMask = (int) ($slotForm['days_of_week'] ?? 31);
$hhmm = static function (mixed $t): string {
    $s = (string) $t;
    return strlen($s) >= 5 ? substr($s, 0, 5) : $s;
};
?>
<?php require __DIR__ . '/_nav.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <div class="path-text mb-1"><a href="/shows">Shows</a> / <?php echo View::e((string) $show['abbreviation']); ?></div>
    <h1 class="h3 mb-1"><?php echo View::e((string) $show['canonical_name']); ?></h1>
    <p class="path-text mb-0">Identity, aliases, and every time slot this show ran.</p>
  </div>
  <a href="/eras" class="btn btn-outline-secondary btn-sm">Broadcast eras</a>
</div>

<?php if ($msg = Session::getFlash('success')): ?>
<div class="alert alert-success"><?php echo View::e((string) $msg); ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
<div class="alert alert-danger"><?php echo View::e((string) $msg); ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Identity</h2>
        <form method="post" action="/shows/update">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <input type="hidden" name="id" value="<?php echo $showId; ?>">
          <div class="mb-2">
            <label class="form-label">Canonical name</label>
            <input type="text" name="canonical_name" class="form-control"
                   value="<?php echo View::e((string) $show['canonical_name']); ?>" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Abbreviation</label>
            <input type="text" name="abbreviation" class="form-control"
                   value="<?php echo View::e((string) $show['abbreviation']); ?>" required>
          </div>
          <div class="mb-2">
            <label class="form-label">Aliases</label>
            <textarea name="aliases" class="form-control" rows="4"><?php echo View::e($aliasText); ?></textarea>
          </div>
          <div class="mb-2">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"><?php echo View::e((string) ($show['notes'] ?? '')); ?></textarea>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="active" id="show-active"
              <?php echo !empty($show['active']) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="show-active">Active</label>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Save identity</button>
        </form>
        <hr>
        <form method="post" action="/shows/delete"
              onsubmit="return confirm('Delete this show and its schedule slots?');">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <input type="hidden" name="id" value="<?php echo $showId; ?>">
          <button type="submit" class="btn btn-outline-danger btn-sm">Delete show</button>
        </form>
      </div>
    </div>

    <div class="card border-0 shadow-sm mt-3">
      <div class="card-body">
        <h2 class="h6 mb-3">Merge into this show</h2>
        <p class="path-text" style="font-size:0.8rem">Absorb duplicates — schedule, catalog files, and aliases move here.</p>
        <form method="post" action="/shows/merge">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <input type="hidden" name="canonical_id" value="<?php echo $showId; ?>">
          <div class="mb-2" style="max-height:180px;overflow:auto">
            <?php foreach ($allShows as $other): ?>
            <?php if ((int) $other['id'] === $showId) { continue; } ?>
            <label class="form-check">
              <input class="form-check-input" type="checkbox" name="absorbed_ids[]" value="<?php echo (int) $other['id']; ?>">
              <span class="form-check-label">
                <code><?php echo View::e((string) $other['abbreviation']); ?></code>
                <?php echo View::e((string) $other['canonical_name']); ?>
              </span>
            </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="btn btn-outline-warning btn-sm"
                  onclick="return confirm('Merge selected shows into this one?');">Merge selected</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7" id="schedule">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-1">Schedule slots</h2>
        <p class="path-text mb-3" style="font-size:0.8rem">
          Each row is a time block this show ran (from → to, or open-ended current).
          Prefer linking a broadcast era when the slot belongs to a known network coverage period.
        </p>

        <form method="post"
              action="/shows/<?php echo $showId; ?>/slots/<?php echo $editSlot ? 'update' : 'create'; ?>"
              class="border rounded p-3 mb-3" style="background:var(--hover-bg)">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <?php if ($editSlot): ?>
          <input type="hidden" name="id" value="<?php echo (int) $editSlot['id']; ?>">
          <?php endif; ?>
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label">Title</label>
              <input type="text" name="title" class="form-control form-control-sm" required
                     value="<?php echo View::e((string) ($slotForm['title'] ?? '')); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Start ET</label>
              <input type="time" name="hour_start_et" class="form-control form-control-sm" required
                     value="<?php echo View::e($hhmm($slotForm['hour_start_et'] ?? '09:00')); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">End ET</label>
              <input type="time" name="hour_end_et" class="form-control form-control-sm" required
                     value="<?php echo View::e($hhmm($slotForm['hour_end_et'] ?? '10:00')); ?>">
            </div>
            <div class="col-12">
              <label class="form-label d-block">Days</label>
              <?php require __DIR__ . '/_slot_days.php'; ?>
            </div>
            <div class="col-md-4">
              <label class="form-label">From</label>
              <input type="date" name="effective_from" class="form-control form-control-sm" required
                     value="<?php echo View::e(substr((string) ($slotForm['effective_from'] ?? date('Y-m-d')), 0, 10)); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">To (blank = current)</label>
              <input type="date" name="effective_to" class="form-control form-control-sm"
                     value="<?php echo View::e($slotForm['effective_to'] ? substr((string) $slotForm['effective_to'], 0, 10) : ''); ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label">Broadcast era</label>
              <select name="broadcast_era_id" class="form-select form-select-sm">
                <option value="">—</option>
                <?php foreach ($eras as $era): ?>
                <option value="<?php echo (int) $era['id']; ?>"
                  <?php echo (int) ($slotForm['broadcast_era_id'] ?? 0) === (int) $era['id'] ? 'selected' : ''; ?>>
                  <?php echo View::e((string) $era['name']); ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label">Notes</label>
              <input type="text" name="notes" class="form-control form-control-sm"
                     value="<?php echo View::e((string) ($slotForm['notes'] ?? '')); ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end">
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="active" id="slot-active"
                  <?php echo !isset($slotForm['active']) || !empty($slotForm['active']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="slot-active">Active</label>
              </div>
            </div>
          </div>
          <div class="mt-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">
              <?php echo $editSlot ? 'Update slot' : 'Add slot'; ?>
            </button>
            <?php if ($editSlot): ?>
            <a href="/shows/<?php echo $showId; ?>#schedule" class="btn btn-outline-secondary btn-sm">Cancel</a>
            <?php endif; ?>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>Hours</th>
                <th>Days</th>
                <th>From → To</th>
                <th>Era</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if ($slots === []): ?>
              <tr><td colspan="5" class="path-text text-center py-3">No slots yet — add one above or adopt from an era.</td></tr>
              <?php else: ?>
              <?php foreach ($slots as $slot): ?>
              <tr>
                <td class="text-nowrap">
                  <?php echo View::e($hhmm($slot['hour_start_et'])); ?>–<?php echo View::e($hhmm($slot['hour_end_et'])); ?>
                </td>
                <td class="path-text" style="font-size:0.75rem">
                  <?php echo View::e(ScheduleEntryParser::daysLabel((int) $slot['days_of_week'])); ?>
                </td>
                <td class="path-text text-nowrap" style="font-size:0.78rem">
                  <?php echo View::e(substr((string) $slot['effective_from'], 0, 10)); ?>
                  →
                  <?php echo $slot['effective_to'] ? View::e(substr((string) $slot['effective_to'], 0, 10)) : 'current'; ?>
                </td>
                <td class="path-text" style="font-size:0.75rem">
                  <?php echo View::e((string) ($slot['era_name'] ?: '—')); ?>
                </td>
                <td class="text-end text-nowrap">
                  <a class="btn btn-outline-secondary btn-xs"
                     href="/shows/<?php echo $showId; ?>?edit_slot=<?php echo (int) $slot['id']; ?>#schedule">Edit</a>
                  <?php if (empty($slot['effective_to'])): ?>
                  <form method="post" action="/shows/<?php echo $showId; ?>/slots/close" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $slot['id']; ?>">
                    <input type="hidden" name="effective_to" value="<?php echo View::e(date('Y-m-d')); ?>">
                    <button type="submit" class="btn btn-outline-secondary btn-xs"
                            onclick="return confirm('Close this open-ended slot as of today?');">Close</button>
                  </form>
                  <?php endif; ?>
                  <form method="post" action="/shows/<?php echo $showId; ?>/slots/delete" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $slot['id']; ?>">
                    <button type="submit" class="btn btn-outline-danger btn-xs"
                            onclick="return confirm('Delete this slot?');">Del</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
