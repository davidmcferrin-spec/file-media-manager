<?php

declare(strict_types=1);

use MediaManager\Auth\Session;
use MediaManager\Support\View;

/** @var int $splitFlagMinutes */
/** @var int $splitStrongMinutes */
/** @var int $audioContentGapMinutes */
/** @var int $audioMinProgramMinutes */
/** @var int $audioAdIgnoreMinutes */
/** @var float $audioSilenceNoiseDb */
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
            Files at or above this duration are flagged <code>needs_split</code> (default 120 minutes / 2 hours).
            Schedule spans across more than one hourly show block are also flagged.
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Strong split duration (minutes)</label>
          <input type="number" name="split_strong_minutes" class="form-control" min="1" max="2880" step="1" required
                 value="<?php echo (int) $splitStrongMinutes; ?>">
          <div class="form-text" style="color:var(--text-soft)">
            At or above this duration, split notes use the stronger wording (default 180 minutes / 3 hours).
            Must be greater than or equal to the split flag duration.
          </div>
        </div>

        <div class="col-12">
          <hr class="my-1">
          <div class="path-text" style="font-size:0.78rem">
            <strong>Audio split suggest</strong> (Split workbench — when captions are unavailable).
            FFmpeg scans loudness silence on demand; results are cached per file.
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Content quiet gap (minutes)</label>
          <input type="number" name="split_audio_content_gap_minutes" class="form-control" min="5" max="180" step="1" required
                 value="<?php echo (int) $audioContentGapMinutes; ?>">
          <div class="form-text" style="color:var(--text-soft)">
            Quiet this long or longer separates programs / trims dead air (default 30).
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Min program hold (minutes)</label>
          <input type="number" name="split_audio_min_program_minutes" class="form-control" min="1" max="120" step="1" required
                 value="<?php echo (int) $audioMinProgramMinutes; ?>">
          <div class="form-text" style="color:var(--text-soft)">
            Sustained activity required to count as a program start (default 9).
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Ignore short dips (minutes)</label>
          <input type="number" name="split_audio_ad_ignore_minutes" class="form-control" min="1" max="30" step="1" required
                 value="<?php echo (int) $audioAdIgnoreMinutes; ?>">
          <div class="form-text" style="color:var(--text-soft)">
            Shorter quiet (ads) stays inside a program; used to nudge hour cuts (default 5).
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Silence noise floor (dB)</label>
          <input type="number" name="split_audio_silence_noise_db" class="form-control" min="-80" max="-5" step="1" required
                 value="<?php echo (float) $audioSilenceNoiseDb; ?>">
          <div class="form-text" style="color:var(--text-soft)">
            FFmpeg <code>silencedetect</code> noise threshold (default −35). Lower = stricter silence.
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
