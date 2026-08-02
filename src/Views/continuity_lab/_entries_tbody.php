<?php

declare(strict_types=1);

use MediaManager\Services\ContinuityCheckService;
use MediaManager\Services\DateNormalizer;
use MediaManager\Services\ProposalPathBuilder;
use MediaManager\Support\View;

/** @var list<array<string, mixed>> $entries */
/** @var array<int, string> $showAbbrById */
$showAbbrById = $showAbbrById ?? [];

/**
 * First non-empty string among candidates (after trim).
 *
 * @param mixed ...$candidates
 */
$continuityFirst = static function (mixed ...$candidates): string {
    foreach ($candidates as $c) {
        if ($c === null) {
            continue;
        }
        $s = trim((string) $c);
        if ($s !== '') {
            return $s;
        }
    }

    return '';
};

/** Normalize date display to YYYYMMDD when possible. */
$continuityDate = static function (string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $norm = ProposalPathBuilder::normalizeDateInput($raw);

    return $norm ?? $raw;
};

/** Normalize time display to HHMM when possible. */
$continuityTime = static function (string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    return DateNormalizer::normalizeTime($raw) ?? $raw;
};
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
        $seedProposal = [];
        if (is_array($seed)) {
            if (is_array($seed['catalog_proposal'] ?? null)) {
                $seedProposal = $seed['catalog_proposal'];
            } elseif (is_array($seed['proposal'] ?? null)) {
                $seedProposal = $seed['proposal'];
            }
        }

        $engineRaw = trim((string) ($row['engine_raw'] ?? ''));
        $engineReply = null;
        if ($engineRaw !== '') {
            $decodedReply = json_decode($engineRaw, true);
            if (is_array($decodedReply)) {
                $engineReply = $decodedReply;
                // Repair payloads may append a second JSON block — prefer last object-looking decode.
                if (str_contains($engineRaw, '--- repair ---')) {
                    $parts = preg_split('/\n--- repair ---\n/', $engineRaw) ?: [];
                    $last = trim((string) end($parts));
                    $lastDecoded = json_decode($last, true);
                    if (is_array($lastDecoded)) {
                        $engineReply = $lastDecoded;
                    }
                }
            }
        }

        $ruleDate = $continuityDate($continuityFirst(
            $row['rule_file_date'] ?? null,
            $seedProposal['file_date'] ?? null
        ));
        $ruleTime = $continuityTime($continuityFirst(
            $row['rule_file_time'] ?? null,
            $seedProposal['file_time'] ?? null
        ));
        $engineDate = $continuityDate($continuityFirst(
            $row['engine_file_date'] ?? null,
            is_array($engineReply) ? ($engineReply['file_date'] ?? null) : null
        ));
        $engineTime = $continuityTime($continuityFirst(
            $row['engine_file_time'] ?? null,
            is_array($engineReply) ? ($engineReply['file_time'] ?? null) : null
        ));
        $finalDate = $continuityDate($continuityFirst(
            $row['final_file_date'] ?? null,
            $ruleDate,
            $engineDate
        ));
        $finalTime = $continuityTime($continuityFirst(
            $row['final_file_time'] ?? null,
            $ruleTime,
            $engineTime
        ));

        $ruleType = trim((string) ($row['rule_media_type_abbr'] ?? ($seedProposal['media_type'] ?? '')));
        $finalType = trim((string) ($row['final_media_type_abbr'] ?? $ruleType));
        $engineType = trim((string) ($row['engine_media_type_abbr'] ?? ''));

        // Resolve model show abbr: seed catalog → show map → id fallback.
        $engineShowId = isset($row['engine_show_id']) && $row['engine_show_id'] !== null && $row['engine_show_id'] !== ''
            ? (int) $row['engine_show_id']
            : 0;
        $engineShow = '';
        if ($engineShowId > 0 && is_array($seed) && is_array($seed['shows'] ?? null)) {
            foreach ($seed['shows'] as $s) {
                if (!is_array($s)) {
                    continue;
                }
                if ((int) ($s['id'] ?? 0) === $engineShowId) {
                    $engineShow = trim((string) ($s['abbreviation'] ?? ''));
                    break;
                }
            }
        }
        if ($engineShow === '' && $engineShowId > 0) {
            $engineShow = trim((string) ($showAbbrById[$engineShowId] ?? ''));
        }
        // Model column shows engine-stated values only (no Rules mirroring).
        $engineShowForName = $engineShow;
        if ($engineShow === '' && $engineShowId > 0) {
            $engineShow = '#' . $engineShowId;
        }
        $engineDt = trim($engineDate . ($engineDate !== '' && $engineTime !== '' ? ' ' : '') . $engineTime);
        $ruleDt = trim($ruleDate . ($ruleDate !== '' && $ruleTime !== '' ? ' ' : '') . $ruleTime);
        $finalDt = trim($finalDate . ($finalDate !== '' && $finalTime !== '' ? ' ' : '') . $finalTime);

        $ruleConf = trim((string) ($row['rule_confidence'] ?? ''));
        $engineConf = trim((string) ($row['engine_confidence'] ?? ''));
        $finalConf = trim((string) ($row['final_confidence'] ?? ''));

        $seedPretty = '';
        if (is_array($seed)) {
            $seedPretty = (string) json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $transportErr = trim((string) ($row['transport_error'] ?? ''));
        $hasArtifacts = $seedPretty !== '' || $engineRaw !== '' || $transportErr !== '';
        $artifactsBundle = '';
        if ($hasArtifacts) {
            $engineReplyOut = $engineRaw !== '' ? $engineRaw : null;
            if (is_array($engineReply)) {
                $engineReplyOut = $engineReply;
            }
            $artifactsBundle = (string) json_encode([
                'decision_id'     => $rowId,
                'outcome'         => $outcome,
                'http_status'     => $row['http_status'] ?? null,
                'transport_error' => $transportErr !== '' ? $transportErr : null,
                'comparison'      => [
                    'confidence' => ['rules' => $ruleConf, 'model' => $engineConf, 'final' => $finalConf],
                    'show'       => ['rules' => $ruleShow, 'model' => $engineShow, 'final' => $finalShow],
                    'media_type' => ['rules' => $ruleType, 'model' => $engineType, 'final' => $finalType],
                    'datetime'   => ['rules' => $ruleDt, 'model' => $engineDt, 'final' => $finalDt],
                ],
                'seed_packet'     => is_array($seed) ? $seed : null,
                'engine_reply'    => $engineReplyOut,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $scheduleCount = is_array($seed) && is_array($seed['schedule'] ?? null) ? count($seed['schedule']) : 0;
        $daySlotCount = is_array($seed) && is_array($seed['day_slots'] ?? null)
            ? count($seed['day_slots'])
            : (is_array($seed) && is_array($seed['timeline'] ?? null) ? count($seed['timeline']) : 0);
        $atAirCount = is_array($seed) && is_array($seed['at_air_time'] ?? null)
            ? count($seed['at_air_time'])
            : 0;
        $exampleCount = is_array($seed) && is_array($seed['examples'] ?? null) ? count($seed['examples']) : 0;
        $showCount = is_array($seed) && is_array($seed['shows'] ?? null) ? count($seed['shows']) : 0;
        $catalogHref = $fileId > 0
            ? '/queue?status=ALL&file_id=' . $fileId
            : '/queue?status=ALL&q=' . rawurlencode((string) ($row['original_filename'] ?? ''));

        $originalName = trim((string) ($row['original_filename'] ?? ''));
        if ($originalName === '') {
            $originalName = trim((string) ($row['original_path'] ?? ''));
        }
        $ruleName = trim((string) ($row['rule_proposed_filename'] ?? ($seedProposal['proposed_filename'] ?? '')));
        $finalName = trim((string) ($row['final_proposed_filename'] ?? ''));
        $modelName = trim((string) ($row['engine_proposed_filename'] ?? ''));
        if ($modelName === '') {
            // Reconstruct only from model-stated parts (older rows before engine_proposed_filename).
            $built = ContinuityCheckService::buildProposedFilename(
                $originalName !== '' ? $originalName : 'file.bin',
                $engineShowForName !== '' ? $engineShowForName : null,
                $engineDate !== '' ? $engineDate : null,
                $engineTime !== '' ? $engineTime : null,
                $engineType !== '' ? $engineType : null
            );
            $modelName = $built ?? '';
        }
        if ($finalName === '' && $ruleName !== '') {
            $finalName = $ruleName;
        }
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
            </div>
            <?php endif; ?>
          </td>
          <td class="text-nowrap">
            <?php echo View::continuityTriad($ruleConf, $engineConf, $finalConf); ?>
          </td>
          <td class="text-nowrap">
            <?php echo View::continuityTriad($ruleShow, $engineShow, $finalShow); ?>
          </td>
          <td class="text-nowrap">
            <?php echo View::continuityTriad($ruleType, $engineType, $finalType); ?>
          </td>
          <td class="text-nowrap">
            <?php echo View::continuityTriad($ruleDt, $engineDt, $finalDt); ?>
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
          <td style="max-width:320px;word-break:break-all">
            <div class="continuity-triad" style="font-size:0.72rem;line-height:1.35">
              <div class="d-flex gap-1">
                <span class="path-text" style="min-width:2.8rem">Orig</span>
                <a href="<?php echo View::e($catalogHref); ?>">
                  <code><?php echo View::e($originalName !== '' ? $originalName : '—'); ?></code>
                </a>
              </div>
            </div>
            <?php echo View::continuityTriad($ruleName, $modelName, $finalName); ?>
            <?php if ($fileId > 0): ?>
            <div class="mt-1" style="font-size:0.68rem">
              <a href="<?php echo View::e($catalogHref); ?>">Catalog #<?php echo $fileId; ?></a>
            </div>
            <?php endif; ?>
          </td>
          <td class="path-text"><?php echo (int) ($row['duration_ms'] ?? 0); ?></td>
          <td>
            <?php if ($hasArtifacts): ?>
            <button type="button"
                    class="btn btn-outline-secondary btn-xs continuity-artifact-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#continuity-artifact-modal"
                    data-decision-id="<?php echo $rowId; ?>"
                    data-outcome="<?php echo View::e($outcome); ?>"
                    data-badge="<?php echo View::e($badge); ?>"
                    data-bundle-id="art-bundle-<?php echo $rowId; ?>"
                    data-panel-id="art-panel-<?php echo $rowId; ?>">
              Artifacts
            </button>
            <textarea id="art-bundle-<?php echo $rowId; ?>" class="d-none" readonly><?php echo View::e($artifactsBundle); ?></textarea>
            <template id="art-panel-<?php echo $rowId; ?>">
              <div class="d-flex flex-wrap gap-3 path-text mb-3" style="font-size:0.75rem">
                <span>Shows: <strong><?php echo (int) $showCount; ?></strong></span>
                <span>Schedule: <strong><?php echo (int) $scheduleCount; ?></strong></span>
                <span>Day slots: <strong><?php echo (int) $daySlotCount; ?></strong></span>
                <span>At air time: <strong><?php echo (int) $atAirCount; ?></strong></span>
                <span>Examples: <strong><?php echo (int) $exampleCount; ?></strong></span>
                <?php if ($row['http_status'] !== null): ?>
                <span>HTTP: <strong><?php echo (int) $row['http_status']; ?></strong></span>
                <?php endif; ?>
              </div>
              <div class="mb-3 p-2 rounded" style="background:var(--hover-bg);font-size:0.78rem">
                <div class="form-label mb-2">Decision tree</div>
                <div class="row g-2">
                  <div class="col-md-3">
                    <div class="path-text mb-1">Confidence</div>
                    <?php echo View::continuityTriad($ruleConf, $engineConf, $finalConf); ?>
                  </div>
                  <div class="col-md-3">
                    <div class="path-text mb-1">Show</div>
                    <?php echo View::continuityTriad($ruleShow, $engineShow, $finalShow); ?>
                  </div>
                  <div class="col-md-3">
                    <div class="path-text mb-1">Type</div>
                    <?php echo View::continuityTriad($ruleType, $engineType, $finalType); ?>
                  </div>
                  <div class="col-md-3">
                    <div class="path-text mb-1">Date / Time</div>
                    <?php echo View::continuityTriad($ruleDt, $engineDt, $finalDt); ?>
                  </div>
                </div>
                <?php if (trim((string) ($row['engine_reason'] ?? '')) !== ''): ?>
                <div class="mt-2 path-text">
                  Model reason: <?php echo View::e((string) $row['engine_reason']); ?>
                </div>
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
                  <pre class="mb-0 p-2 rounded" style="font-size:0.68rem;max-height:420px;overflow:auto;white-space:pre;background:rgba(0,0,0,0.25)"><?php echo View::e($seedPretty); ?></pre>
                  <?php else: ?>
                  <div class="path-text">No seed packet stored for this row (logged before artifacts).</div>
                  <?php endif; ?>
                </div>
                <div class="col-lg-5">
                  <div class="form-label mb-1">Engine reply (raw)</div>
                  <?php if ($engineRaw !== ''): ?>
                  <pre class="mb-0 p-2 rounded" style="font-size:0.68rem;max-height:420px;overflow:auto;white-space:pre-wrap;background:rgba(0,0,0,0.25)"><?php echo View::e($engineRaw); ?></pre>
                  <?php else: ?>
                  <div class="path-text">No raw reply captured.</div>
                  <?php endif; ?>
                </div>
              </div>
            </template>
            <?php else: ?>
            <span class="path-text">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
