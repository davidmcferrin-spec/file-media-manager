<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\ScheduleEntryParser;
use MediaManager\Support\View;

/** @var array<string, mixed> $era */
/** @var list<array<string, mixed>> $windows */
/** @var array{in_era: list<array<string, mixed>>, adoptable: list<array<string, mixed>>, slots: list<array<string, mixed>>} $membership */
/** @var array<string, mixed>|null $editWindow */
$eraId = (int) $era['id'];
$showsTab = 'eras';
$winForm = $editWindow ?? [
    'hour_start_et' => '20:00',
    'hour_end_et' => '22:00',
    'days_of_week' => 31,
    'notes' => '',
];
$daysMask = (int) ($winForm['days_of_week'] ?? 31);
$hhmm = static function (mixed $t): string {
    $s = (string) $t;
    return strlen($s) >= 5 ? substr($s, 0, 5) : $s;
};
?>
<?php require dirname(__DIR__) . '/shows/_nav.php'; ?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div>
    <div class="path-text mb-1"><a href="/eras">Eras</a> / <?php echo View::e((string) $era['name']); ?></div>
    <h1 class="h3 mb-1"><?php echo View::e((string) $era['name']); ?></h1>
    <p class="path-text mb-0">
      <?php echo View::e(substr((string) $era['effective_from'], 0, 10)); ?>
      →
      <?php echo $era['effective_to'] ? View::e(substr((string) $era['effective_to'], 0, 10)) : 'current'; ?>
    </p>
  </div>
</div>

<?php if ($msg = Session::getFlash('success')): ?>
<div class="alert alert-success"><?php echo View::e((string) $msg); ?></div>
<?php endif; ?>
<?php if ($msg = Session::getFlash('error')): ?>
<div class="alert alert-danger"><?php echo View::e((string) $msg); ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-body">
        <h2 class="h6 mb-3">Era details</h2>
        <form method="post" action="/eras/update">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <input type="hidden" name="id" value="<?php echo $eraId; ?>">
          <div class="mb-2">
            <label class="form-label">Name</label>
            <input type="text" name="name" class="form-control" required
                   value="<?php echo View::e((string) $era['name']); ?>">
          </div>
          <div class="mb-2">
            <label class="form-label">From</label>
            <input type="date" name="effective_from" class="form-control" required
                   value="<?php echo View::e(substr((string) $era['effective_from'], 0, 10)); ?>">
          </div>
          <div class="mb-2">
            <label class="form-label">To (blank = current)</label>
            <input type="date" name="effective_to" class="form-control"
                   value="<?php echo $era['effective_to'] ? View::e(substr((string) $era['effective_to'], 0, 10)) : ''; ?>">
          </div>
          <div class="mb-2">
            <label class="form-label">Sort order</label>
            <input type="number" name="sort_order" class="form-control"
                   value="<?php echo (int) ($era['sort_order'] ?? 0); ?>">
          </div>
          <div class="mb-2">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"><?php echo View::e((string) ($era['notes'] ?? '')); ?></textarea>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="active" id="era-active"
              <?php echo !empty($era['active']) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="era-active">Active</label>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Save era</button>
        </form>
        <hr>
        <form method="post" action="/eras/delete"
              onsubmit="return confirm('Delete this era and its windows? Schedule slots keep their hours but lose the era link.');">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <input type="hidden" name="id" value="<?php echo $eraId; ?>">
          <button type="submit" class="btn btn-outline-danger btn-sm">Delete era</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8" id="windows">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body">
        <h2 class="h6 mb-1">Broadcast windows</h2>
        <p class="path-text mb-3" style="font-size:0.8rem">
          Hours the network was on air during this era (not individual shows).
        </p>
        <form method="post"
              action="/eras/<?php echo $eraId; ?>/windows/<?php echo $editWindow ? 'update' : 'create'; ?>"
              class="border rounded p-3 mb-3" style="background:var(--hover-bg)">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <?php if ($editWindow): ?>
          <input type="hidden" name="id" value="<?php echo (int) $editWindow['id']; ?>">
          <?php endif; ?>
          <div class="row g-2">
            <div class="col-md-3">
              <label class="form-label">Start ET</label>
              <input type="time" name="hour_start_et" class="form-control form-control-sm" required
                     value="<?php echo View::e($hhmm($winForm['hour_start_et'] ?? '20:00')); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">End ET</label>
              <input type="time" name="hour_end_et" class="form-control form-control-sm" required
                     value="<?php echo View::e($hhmm($winForm['hour_end_et'] ?? '22:00')); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Notes</label>
              <input type="text" name="notes" class="form-control form-control-sm"
                     value="<?php echo View::e((string) ($winForm['notes'] ?? '')); ?>">
            </div>
            <div class="col-12">
              <label class="form-label d-block">Days</label>
              <?php require dirname(__DIR__) . '/shows/_slot_days.php'; ?>
            </div>
          </div>
          <div class="mt-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm">
              <?php echo $editWindow ? 'Update window' : 'Add window'; ?>
            </button>
            <?php if ($editWindow): ?>
            <a href="/eras/<?php echo $eraId; ?>#windows" class="btn btn-outline-secondary btn-sm">Cancel</a>
            <?php endif; ?>
          </div>
        </form>

        <table class="table table-sm align-middle">
          <thead>
            <tr><th>Hours</th><th>Days</th><th>Notes</th><th></th></tr>
          </thead>
          <tbody>
            <?php if ($windows === []): ?>
            <tr><td colspan="4" class="path-text text-center py-3">No windows — add the network’s on-air blocks for this era.</td></tr>
            <?php else: ?>
            <?php foreach ($windows as $w): ?>
            <tr>
              <td class="text-nowrap"><?php echo View::e($hhmm($w['hour_start_et'])); ?>–<?php echo View::e($hhmm($w['hour_end_et'])); ?></td>
              <td class="path-text" style="font-size:0.75rem"><?php echo View::e(ScheduleEntryParser::daysLabel((int) $w['days_of_week'])); ?></td>
              <td class="path-text" style="font-size:0.75rem"><?php echo View::e((string) ($w['notes'] ?: '—')); ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-outline-secondary btn-xs"
                   href="/eras/<?php echo $eraId; ?>?edit_window=<?php echo (int) $w['id']; ?>#windows">Edit</a>
                <form method="post" action="/eras/<?php echo $eraId; ?>/windows/delete" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
                  <input type="hidden" name="id" value="<?php echo (int) $w['id']; ?>">
                  <button type="submit" class="btn btn-outline-danger btn-xs"
                          onclick="return confirm('Delete this window?');">Del</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card border-0 shadow-sm" id="shows">
      <div class="card-body">
        <h2 class="h6 mb-1">Shows in this era</h2>
        <p class="path-text mb-3" style="font-size:0.8rem">
          Linked by schedule overlap or explicit era adopt. Adopt creates Timeline slots from this era’s windows.
        </p>

        <?php if ($membership['in_era'] !== []): ?>
        <div class="mb-3">
          <div class="form-label">Already on Timeline for this era</div>
          <ul class="list-unstyled mb-0">
            <?php foreach ($membership['in_era'] as $s): ?>
            <li class="mb-1">
              <a href="/shows/<?php echo (int) $s['id']; ?>">
                <code><?php echo View::e((string) $s['abbreviation']); ?></code>
                <?php echo View::e((string) $s['canonical_name']); ?>
              </a>
            </li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>

        <?php if ($windows !== [] && $membership['adoptable'] !== []): ?>
        <form method="post" action="/eras/<?php echo $eraId; ?>/adopt" class="border rounded p-3" style="background:var(--hover-bg)">
          <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
          <div class="mb-2">
            <label class="form-label">Adopt show into era</label>
            <select name="show_id" class="form-select form-select-sm" required>
              <option value="">Select show…</option>
              <?php foreach ($membership['adoptable'] as $s): ?>
              <option value="<?php echo (int) $s['id']; ?>">
                <?php echo View::e((string) $s['abbreviation'] . ' — ' . $s['canonical_name']); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-2">
            <label class="form-label d-block">Windows to use (default: all)</label>
            <?php foreach ($windows as $w): ?>
            <label class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="window_ids[]" value="<?php echo (int) $w['id']; ?>" checked>
              <span class="form-check-label path-text" style="font-size:0.78rem">
                <?php echo View::e($hhmm($w['hour_start_et']) . '–' . $hhmm($w['hour_end_et'])); ?>
              </span>
            </label>
            <?php endforeach; ?>
          </div>
          <button type="submit" class="btn btn-primary btn-sm"
                  onclick="return confirm('Create schedule slots for this show from the selected windows?');">
            Adopt show
          </button>
        </form>
        <?php elseif ($windows === []): ?>
        <p class="path-text mb-0">Add broadcast windows before adopting shows.</p>
        <?php else: ?>
        <p class="path-text mb-0">All active shows already appear in this era.</p>
        <?php endif; ?>

        <?php if ($membership['slots'] !== []): ?>
        <hr>
        <div class="form-label">Slots linked to this era</div>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead><tr><th>Show</th><th>Hours</th><th>Days</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($membership['slots'] as $slot): ?>
              <tr>
                <td><code><?php echo View::e((string) $slot['show_abbr']); ?></code></td>
                <td><?php echo View::e($hhmm($slot['hour_start_et']) . '–' . $hhmm($slot['hour_end_et'])); ?></td>
                <td class="path-text" style="font-size:0.75rem"><?php echo View::e(ScheduleEntryParser::daysLabel((int) $slot['days_of_week'])); ?></td>
                <td><a href="/shows/<?php echo (int) $slot['show_id']; ?>#schedule">Open show</a></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
