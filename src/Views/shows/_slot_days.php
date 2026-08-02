<?php

declare(strict_types=1);

use MediaManager\Support\ScheduleEntryParser;
use MediaManager\Support\View;

/** @var int $daysMask */
$daysMask = (int) ($daysMask ?? 0);
foreach (ScheduleEntryParser::dayOptions() as $opt): ?>
<label class="form-check form-check-inline">
  <input class="form-check-input" type="checkbox" name="days[]" value="<?php echo (int) $opt['bit']; ?>"
    <?php echo (($daysMask & $opt['bit']) !== 0) ? 'checked' : ''; ?>>
  <span class="form-check-label"><?php echo View::e($opt['label']); ?></span>
</label>
<?php endforeach; ?>
