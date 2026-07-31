<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var int $splitFlagMinutes */
/** @var int $splitStrongMinutes */
/** @var bool $continuityCheckEnabled */
?>

<div class="card mb-4">
  <div class="card-header">Processing</div>
  <div class="card-body">
    <p class="path-text mb-3" style="font-size:0.78rem">
      Controls used during Scan / Rescan / Reclassify. Changes apply to <strong>new</strong> classification runs —
      existing files keep their flags until you rescan or reclassify.
    </p>
    <form method="post" action="/settings/processing">
      <input type="hidden" name="_csrf" value="<?php echo View::e(Session::csrfToken()); ?>">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Split flag duration (minutes)</label>
          <input type="number" name="split_flag_minutes" class="form-control" min="1" max="1440" step="1" required
                 value="<?php echo (int) $splitFlagMinutes; ?>">
          <div class="form-text" style="color:var(--text-soft)">
            Files at or above this duration are flagged <code>needs_split</code> (default often 90 minutes / 1.5 hours).
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Strong split duration (minutes)</label>
          <input type="number" name="split_strong_minutes" class="form-control" min="1" max="2880" step="1" required
                 value="<?php echo (int) $splitStrongMinutes; ?>">
          <div class="form-text" style="color:var(--text-soft)">
            Higher confidence / stronger split signal threshold used by the classifier.
          </div>
        </div>
        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="continuity_check_enabled" id="continuity-check"
                   value="1" <?php echo !empty($continuityCheckEnabled) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="continuity-check">
              Broadcast continuity check
            </label>
          </div>
          <div class="form-text" style="color:var(--text-soft)">
            Quiet second pass during Scan / Rescan / Reclassify. Cross-checks proposed show mapping against
            the show dictionary, timeline, and recently approved catalog items, and dials down overconfident hits.
            Requires the continuity engine installed by <code>setup.sh</code>. If the engine is offline, classification continues normally.
            Parallelism: <code>CONTINUITY_CHECK_CONCURRENCY</code> (app) should match Ollama
            <code>OLLAMA_NUM_PARALLEL</code> (default 4).
          </div>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-sm mt-3">Save Processing Settings</button>
    </form>
  </div>
</div>
