<?php

declare(strict_types=1);

use MediaManager\Support\View;

/** @var list<array<string, mixed>> $entries */
?>
        <?php if ($entries === []): ?>
        <tr>
          <td colspan="10" class="text-center py-4 path-text">
            No continuity decisions logged yet. Run a Scan or Reclassify with continuity enabled.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($entries as $row): ?>
        <?php
        $rowId = (int) ($row['id'] ?? 0);
        $fileId = (int) ($row['file_id'] ?? 0);
        $outcome = (string) ($row['outcome'] ?? '');
        $badge = match ($outcome) {
            'confirmed' => 'bg-success',
            'conflict'  => 'bg-warning text-dark',
            'review'    => 'bg-info text-dark',
            'error'     => 'bg-danger',
            default     => 'bg-secondary',
        };
        $ruleShow = trim((string) ($row['rule_show_abbr'] ?? ''));
        $finalShow = trim((string) ($row['final_show_abbr'] ?? ''));
        $showChanged = $ruleShow !== '' && $finalShow !== '' && strcasecmp($ruleShow, $finalShow) !== 0;
        $signalsRaw = $row['rule_signals'] ?? '[]';
        if (is_string($signalsRaw)) {
            $signals = json_decode($signalsRaw, true);
        } else {
            $signals = $signalsRaw;
        }
        if (!is_array($signals)) {
            $signals = [];
        }
        $seedRaw = $row['seed_packet'] ?? null;
        if (is_string($seedRaw) && $seedRaw !== '') {
            $seed = json_decode($seedRaw, true);
        } elseif (is_array($seedRaw)) {
            $seed = $seedRaw;
        } else {
            $seed = null;
        }
        $seedProposal = is_array($seed) && is_array($seed['proposal'] ?? null) ? $seed['proposal'] : [];
        // Fallback date/time from seed for rows logged before dedicated columns.
        $ruleDate = trim((string) ($row['rule_file_date'] ?? ($seedProposal['file_date'] ?? '')));
        $ruleTime = trim((string) ($row['rule_file_time'] ?? ($seedProposal['file_time'] ?? '')));
        $finalDate = trim((string) ($row['final_file_date'] ?? $ruleDate));
        $finalTime = trim((string) ($row['final_file_time'] ?? $ruleTime));
        $engineDate = trim((string) ($row['engine_file_date'] ?? ''));
        $engineTime = trim((string) ($row['engine_file_time'] ?? ''));
        $dateChanged = $ruleDate !== '' && $finalDate !== '' && $ruleDate !== $finalDate;
        $timeChanged = $ruleTime !== '' && $finalTime !== '' && $ruleTime !== $finalTime;
        $ruleType = trim((string) ($row['rule_media_type_abbr'] ?? ($seedProposal['media_type'] ?? '')));
        $finalType = trim((string) ($row['final_media_type_abbr'] ?? $ruleType));
        $engineType = trim((string) ($row['engine_media_type_abbr'] ?? ''));
        $typeChanged = $ruleType !== '' && $finalType !== '' && strcasecmp($ruleType, $finalType) !== 0;
        $seedPretty = '';
        if (is_array($seed)) {
            $seedPretty = (string) json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $engineRaw = trim((string) ($row['engine_raw'] ?? ''));
        $transportErr = trim((string) ($row['transport_error'] ?? ''));
        $hasArtifacts = $seedPretty !== '' || $engineRaw !== '' || $transportErr !== '';
        $scheduleCount = is_array($seed) && is_array($seed['schedule'] ?? null) ? count($seed['schedule']) : 0;
        $atAirCount = is_array($seed) && is_array($seed['at_air_time'] ?? null)
            ? count($seed['at_air_time'])
            : (is_array($seed) && is_array($seed['timeline'] ?? null) ? count($seed['timeline']) : 0);
        $exampleCount = is_array($seed) && is_array($seed['examples'] ?? null) ? count($seed['examples']) : 0;
        $showCount = is_array($seed) && is_array($seed['shows'] ?? null) ? count($seed['shows']) : 0;
        $catalogHref = $fileId > 0
            ? '/queue?status=ALL&file_id=' . $fileId
            : '/queue?status=ALL&q=' . rawurlencode((string) ($row['original_filename'] ?? ''));
        ?>
        <tr>
          <td class="path-text text-nowrap">
            <?php echo View::e(substr((string) ($row['created_at'] ?? ''), 0, 19)); ?>
          </td>
          <td>
            <span class="badge <?php echo View::e($badge); ?>"><?php echo View::e($outcome); ?></span>
            <?php if ($row['engine_agree'] !== null): ?>
            <div class="path-text mt-1">
              agree=<?php echo !empty($row['engine_agree']) ? 'yes' : 'no'; ?>
              <?php if (!empty($row['engine_confidence'])): ?>
              · eng <?php echo View::e((string) $row['engine_confidence']); ?>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="text-nowrap">
            <code><?php echo View::e((string) ($row['rule_confidence'] ?? '')); ?></code>
            →
            <code><?php echo View::e((string) ($row['final_confidence'] ?? '')); ?></code>
          </td>
          <td>
            <?php if ($showChanged): ?>
            <code><?php echo View::e($ruleShow); ?></code>
            →
            <code><?php echo View::e($finalShow); ?></code>
            <?php else: ?>
            <code><?php echo View::e($finalShow !== '' ? $finalShow : ($ruleShow !== '' ? $ruleShow : '—')); ?></code>
            <?php endif; ?>
          </td>
          <td class="text-nowrap">
            <?php if ($finalType !== '' || $ruleType !== ''): ?>
              <?php if ($typeChanged): ?>
              <code><?php echo View::e($ruleType); ?></code>
              →
              <code><?php echo View::e($finalType); ?></code>
              <?php else: ?>
              <code><?php echo View::e($finalType !== '' ? $finalType : $ruleType); ?></code>
              <?php endif; ?>
              <?php if ($engineType !== ''): ?>
              <div class="path-text" style="font-size:0.68rem">eng <?php echo View::e($engineType); ?></div>
              <?php endif; ?>
            <?php else: ?>
            —
            <?php endif; ?>
          </td>
          <td class="text-nowrap path-text">
            <?php if ($finalDate !== '' || $finalTime !== ''): ?>
              <?php if ($dateChanged || $timeChanged): ?>
              <code><?php echo View::e(trim($ruleDate . ' ' . $ruleTime)); ?></code>
              →
              <code><?php echo View::e(trim($finalDate . ' ' . $finalTime)); ?></code>
              <?php else: ?>
              <code><?php echo View::e(trim($finalDate . ' ' . $finalTime)); ?></code>
              <?php endif; ?>
              <?php if ($engineDate !== '' || $engineTime !== ''): ?>
              <div style="font-size:0.68rem">eng <?php echo View::e(trim($engineDate . ' ' . $engineTime)); ?></div>
              <?php endif; ?>
            <?php else: ?>
            —
            <?php endif; ?>
          </td>
          <td style="max-width:280px">
            <?php if (trim((string) ($row['engine_reason'] ?? '')) !== ''): ?>
            <div><?php echo View::e((string) $row['engine_reason']); ?></div>
            <?php endif; ?>
            <?php if ($signals !== []): ?>
            <div class="path-text mt-1" style="font-size:0.7rem">
              <?php echo View::e(implode(' · ', array_slice(array_map('strval', $signals), 0, 4))); ?>
              <?php if (count($signals) > 4): ?>…<?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($row['signal'])): ?>
            <div class="path-text" style="font-size:0.7rem"><?php echo View::e((string) $row['signal']); ?></div>
            <?php endif; ?>
          </td>
          <td class="path-text" style="max-width:260px;word-break:break-all">
            <a href="<?php echo View::e($catalogHref); ?>">
              <?php echo View::e((string) ($row['original_filename'] ?: $row['original_path'])); ?>
            </a>
            <?php if ($fileId > 0): ?>
            <div class="mt-1" style="font-size:0.68rem">
              <a href="<?php echo View::e($catalogHref); ?>">Catalog #<?php echo $fileId; ?></a>
            </div>
            <?php endif; ?>
            <?php if (!empty($row['final_proposed_filename']) || !empty($row['rule_proposed_filename'])): ?>
            <div class="mt-1">
              → <?php echo View::e((string) ($row['final_proposed_filename'] ?? $row['rule_proposed_filename'])); ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="path-text"><?php echo (int) ($row['duration_ms'] ?? 0); ?></td>
          <td>
            <?php if ($hasArtifacts): ?>
            <button type="button" class="btn btn-outline-secondary btn-xs"
                    data-bs-toggle="collapse" data-bs-target="#art-<?php echo $rowId; ?>"
                    aria-expanded="false">
              Artifacts
            </button>
            <?php else: ?>
            <span class="path-text">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($hasArtifacts): ?>
        <tr class="collapse-row">
          <td colspan="9" class="p-0 border-0">
            <div class="collapse" id="art-<?php echo $rowId; ?>">
              <div class="p-3" style="background:var(--hover-bg);border-top:1px solid var(--bs-border-color)">
                <div class="d-flex flex-wrap gap-3 mb-2 path-text" style="font-size:0.75rem">
                  <span>Shows seeded: <strong><?php echo (int) $showCount; ?></strong></span>
                  <span>Schedule rows: <strong><?php echo (int) $scheduleCount; ?></strong></span>
                  <span>At air time: <strong><?php echo (int) $atAirCount; ?></strong></span>
                  <span>Approved examples: <strong><?php echo (int) $exampleCount; ?></strong></span>
                  <?php if ($row['http_status'] !== null): ?>
                  <span>HTTP: <strong><?php echo (int) $row['http_status']; ?></strong></span>
                  <?php endif; ?>
                </div>
                <?php if ($transportErr !== ''): ?>
                <div class="mb-2">
                  <div class="form-label mb-1">Transport</div>
                  <pre class="mb-0 p-2 rounded" style="font-size:0.72rem;white-space:pre-wrap;background:rgba(0,0,0,0.25)"><?php echo View::e($transportErr); ?></pre>
                </div>
                <?php endif; ?>
                <div class="row g-3">
                  <div class="col-lg-7">
                    <div class="form-label mb-1">Seed packet (what continuity saw)</div>
                    <?php if ($seedPretty !== ''): ?>
                    <pre class="mb-0 p-2 rounded" style="font-size:0.68rem;max-height:360px;overflow:auto;white-space:pre;background:rgba(0,0,0,0.25)"><?php echo View::e($seedPretty); ?></pre>
                    <?php else: ?>
                    <div class="path-text">No seed packet stored for this row (logged before artifacts).</div>
                    <?php endif; ?>
                  </div>
                  <div class="col-lg-5">
                    <div class="form-label mb-1">Engine reply (raw)</div>
                    <?php if ($engineRaw !== ''): ?>
                    <pre class="mb-0 p-2 rounded" style="font-size:0.68rem;max-height:360px;overflow:auto;white-space:pre-wrap;background:rgba(0,0,0,0.25)"><?php echo View::e($engineRaw); ?></pre>
                    <?php else: ?>
                    <div class="path-text">No raw reply captured.</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php endif; ?>
