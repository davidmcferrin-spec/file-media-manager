<?php

declare(strict_types=1);

namespace MediaManager\Support;

class View
{
    /**
     * Escape a value for safe HTML output.
     */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Format bytes as human-readable size.
     */
    public static function filesize(int|float|null $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $bytes = (int) $bytes;
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1073741824, 2) . ' GB';
    }

    /**
     * Format seconds as H:MM:SS
     */
    public static function duration(int|float|null $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }
        $s = (int) $seconds;
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        $s = $s % 60;
        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%d:%02d', $m, $s);
    }

    /**
     * Compact technical summary for queue Meta column (from scan-time ffprobe fields).
     *
     * @param array<string, mixed> $file
     */
    public static function mediaTechSummary(array $file): string
    {
        $parts = [];
        if (!empty($file['resolution'])) {
            $parts[] = (string) $file['resolution'];
        }
        if (!empty($file['codec_video'])) {
            $parts[] = strtoupper((string) $file['codec_video']);
        }
        if (!empty($file['codec_audio'])) {
            $parts[] = 'A:' . strtoupper((string) $file['codec_audio']);
        }
        if (!empty($file['container'])) {
            $parts[] = strtoupper((string) $file['container']);
        }
        if ($parts === []) {
            return empty($file['metadata_extracted']) ? 'No metadata' : '—';
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public static function mediaMetaPayload(array $file): array
    {
        return [
            'duration'            => $file['duration_seconds'] ?? null,
            'duration_label'      => self::duration(
                isset($file['duration_seconds']) ? (float) $file['duration_seconds'] : null
            ),
            'resolution'          => $file['resolution'] ?? null,
            'codec_video'         => $file['codec_video'] ?? null,
            'codec_audio'         => $file['codec_audio'] ?? null,
            'framerate'           => $file['framerate'] ?? null,
            'container'           => $file['container'] ?? null,
            'filesize_bytes'      => $file['filesize_bytes'] ?? null,
            'filesize_label'      => self::filesize(
                isset($file['filesize_bytes']) ? (int) $file['filesize_bytes'] : null
            ),
            'metadata_extracted'  => !empty($file['metadata_extracted']),
        ];
    }

    /**
     * Confidence badge HTML.
     */
    public static function confidenceBadge(string $confidence): string
    {
        $map = [
            'HIGH'   => ['success', 'HIGH'],
            'MEDIUM' => ['warning', 'MED'],
            'LOW'    => ['danger',  'LOW'],
        ];
        [$cls, $label] = $map[$confidence] ?? ['secondary', $confidence];
        return '<span class="badge bg-' . $cls . '">' . $label . '</span>';
    }

    /**
     * Status badge HTML.
     */
    public static function statusBadge(string $status): string
    {
        $map = [
            'PENDING'     => ['secondary', 'Pending'],
            'APPROVED'    => ['primary',   'Approved'],
            'REJECTED'    => ['dark',      'Rejected'],
            'FLAGGED'     => ['warning',   'Flagged'],
            'EXECUTED'    => ['success',   'Executed'],
            'ROLLED_BACK' => ['info',      'Rolled Back'],
            'IN_PROGRESS' => ['info',      'In Progress'],
            'DONE'        => ['success',   'Done'],
            'FAILED'      => ['danger',    'Failed'],
        ];
        [$cls, $label] = $map[$status] ?? ['secondary', $status];
        return '<span class="badge bg-' . $cls . '">' . self::e($label) . '</span>';
    }

    /**
     * Page numbers for pagination UI: [1, '…', 4, 5, 6, '…', 100]
     *
     * @return list<int|string>
     */
    public static function paginationRange(int $page, int $totalPages, int $window = 2): array
    {
        if ($totalPages <= 1) {
            return [1];
        }

        $page = max(1, min($page, $totalPages));
        $pages = [1];

        $left  = max(2, $page - $window);
        $right = min($totalPages - 1, $page + $window);

        if ($left > 2) {
            $pages[] = '…';
        }

        for ($p = $left; $p <= $right; $p++) {
            $pages[] = $p;
        }

        if ($right < $totalPages - 1) {
            $pages[] = '…';
        }

        if ($totalPages > 1) {
            $pages[] = $totalPages;
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function paginationUrl(string $basePath, array $query, int $page): string
    {
        $params = $query;
        if ($page > 1) {
            $params['page'] = (string) $page;
        } else {
            unset($params['page']);
        }

        $filtered = array_filter(
            $params,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== false
        );

        $qs = http_build_query($filtered);

        return $basePath . ($qs !== '' ? '?' . $qs : '');
    }

    /**
     * @param list<array{label: string, count: int}> $slices
     */
    public static function pieChartHtml(array $slices, int $size = 220): string
    {
        $total = array_sum(array_column($slices, 'count'));
        if ($total <= 0) {
            return '<p class="path-text mb-0" style="color:var(--text-soft)">No data yet.</p>';
        }

        $colors = [
            '#56c4f5', '#22c55e', '#facc15', '#f87171', '#a78bfa',
            '#fb923c', '#2dd4bf', '#f472b6', '#94a3b8', '#e879f9',
            '#38bdf8', '#4ade80', '#fcd34d',
        ];

        $cx     = $size / 2;
        $cy     = $size / 2;
        $radius = ($size / 2) - 4;
        $angle  = 0.0;
        $paths  = [];

        foreach ($slices as $i => $slice) {
            $count = (int) $slice['count'];
            if ($count <= 0) {
                continue;
            }
            $sliceAngle = ($count / $total) * 360.0;
            if ($sliceAngle <= 0) {
                continue;
            }
            $color = $colors[$i % count($colors)];
            $paths[] = sprintf(
                '<path d="%s" fill="%s" stroke="var(--panel)" stroke-width="1"><title>%s: %s (%s%%)</title></path>',
                self::pieSlicePath($cx, $cy, $radius, $angle, $angle + $sliceAngle),
                $color,
                self::e((string) $slice['label']),
                number_format($count),
                number_format(($count / $total) * 100, 1)
            );
            $angle += $sliceAngle;
        }

        $legend = '';
        foreach ($slices as $i => $slice) {
            $count = (int) $slice['count'];
            if ($count <= 0) {
                continue;
            }
            $pct   = ($count / $total) * 100;
            $color = $colors[$i % count($colors)];
            $legend .= sprintf(
                '<div class="d-flex align-items-center gap-2 mb-1" style="font-size:0.76rem">'
                . '<span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:%s"></span>'
                . '<span class="flex-grow-1 text-truncate" title="%s">%s</span>'
                . '<span class="path-text text-nowrap">%s <span style="color:var(--text-soft)">(%s%%)</span></span>'
                . '</div>',
                $color,
                self::e((string) $slice['label']),
                self::e((string) $slice['label']),
                number_format($count),
                number_format($pct, 1)
            );
        }

        return '<div class="d-flex flex-wrap align-items-start gap-3">'
            . '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '" role="img" aria-hidden="true">'
            . implode('', $paths)
            . '</svg>'
            . '<div class="flex-grow-1" style="min-width:160px;max-height:220px;overflow:auto">' . $legend . '</div>'
            . '</div>';
    }

    /**
     * @param list<array{label: string, total_seconds: float, file_count: int, href?: string}> $bars
     */
    public static function hoursBarChartHtml(array $bars): string
    {
        if ($bars === []) {
            return '<p class="path-text mb-0" style="color:var(--text-soft)">No dated content with duration yet.</p>';
        }

        $hours = array_map(
            static fn (array $bar): float => max(0.0, ((float) $bar['total_seconds']) / 3600.0),
            $bars
        );
        $maxHours = max($hours) ?: 1.0;
        $count    = count($bars);

        $leftPad     = 44.0;
        $rightPad    = 12.0;
        $topPad      = 10.0;
        $bottomPad   = 36.0;
        $chartHeight = 140.0;
        $barGap      = 4.0;
        $minBarWidth = 10.0;
        $plotWidth   = min(560.0, max(240.0, ($count * ($minBarWidth + $barGap)) - $barGap));
        $width      = $leftPad + $plotWidth + $rightPad;
        $height     = $topPad + $chartHeight + $bottomPad;
        $barWidth   = ($plotWidth - (($count - 1) * $barGap)) / $count;

        $gridLines = [0.0, 0.25, 0.5, 0.75, 1.0];
        $svg       = '';

        foreach ($gridLines as $pct) {
            $y     = $topPad + $chartHeight - ($pct * $chartHeight);
            $label = self::formatHours($maxHours * $pct);
            $svg .= sprintf(
                '<line x1="%.1F" y1="%.1F" x2="%.1F" y2="%.1F" stroke="var(--border-color)" stroke-width="1"/>',
                $leftPad,
                $y,
                $leftPad + $plotWidth,
                $y
            );
            $svg .= sprintf(
                '<text x="%.1F" y="%.1F" text-anchor="end" fill="var(--text-soft)" font-size="10">%s</text>',
                $leftPad - 6,
                $y + 3,
                self::e($label)
            );
        }

        $barColor = '#56c4f5';
        $hoverColor = '#38bdf8';

        foreach ($bars as $i => $bar) {
            $h        = $hours[$i];
            $barH     = $maxHours > 0 ? ($h / $maxHours) * $chartHeight : 0.0;
            $x        = $leftPad + ($i * ($barWidth + $barGap));
            $y        = $topPad + $chartHeight - $barH;
            $label    = (string) $bar['label'];
            $fileCount = (int) ($bar['file_count'] ?? 0);
            $title    = sprintf(
                '%s: %s hours (%s files)',
                $label,
                self::formatHours((float) $bar['total_seconds']),
                number_format($fileCount)
            );
            $href     = isset($bar['href']) && $bar['href'] !== '' ? (string) $bar['href'] : null;

            $rect = sprintf(
                '<rect x="%.2F" y="%.2F" width="%.2F" height="%.2F" rx="2" fill="%s" class="hours-bar%s">'
                . '<title>%s</title></rect>',
                $x,
                $y,
                $barWidth,
                max(0.0, $barH),
                $barColor,
                $href !== null ? ' hours-bar-clickable' : '',
                self::e($title)
            );

            if ($href !== null) {
                $hitArea = sprintf(
                    '<rect x="%.2F" y="%.2F" width="%.2F" height="%.2F" fill="transparent" pointer-events="all"/>',
                    $x,
                    $topPad,
                    $barWidth,
                    $chartHeight
                );
                $rect = sprintf(
                    '<a href="%s" class="hours-bar-link" pointer-events="bounding-box">%s%s</a>',
                    self::e($href),
                    $hitArea,
                    $rect
                );
            }

            $svg .= $rect;

            $labelY = $topPad + $chartHeight + 14;
            if ($count > 8) {
                $svg .= sprintf(
                    '<text x="%.1F" y="%.1F" text-anchor="end" fill="var(--text-soft)" font-size="9" '
                    . 'transform="rotate(-45 %.1F %.1F)">%s</text>',
                    $x + ($barWidth / 2),
                    $labelY + 10,
                    $x + ($barWidth / 2),
                    $labelY + 10,
                    self::e($label)
                );
            } else {
                $svg .= sprintf(
                    '<text x="%.1F" y="%.1F" text-anchor="middle" fill="var(--text-soft)" font-size="10">%s</text>',
                    $x + ($barWidth / 2),
                    $labelY,
                    self::e($label)
                );
            }
        }

        $style = '<style>'
            . '.hours-bar-chart-wrap{max-width:640px}'
            . '.hours-bar-chart{width:100%;height:auto;display:block}'
            . '.hours-bar-link{cursor:pointer}'
            . '.hours-bar-clickable{transition:fill 0.15s ease;pointer-events:none}'
            . '.hours-bar-link:hover .hours-bar-clickable{fill:' . $hoverColor . '}'
            . '</style>';

        return $style
            . '<div class="hours-bar-chart-wrap">'
            . '<svg class="hours-bar-chart" viewBox="0 0 ' . round($width, 1) . ' ' . round($height, 1) . '" '
            . 'role="img" aria-label="Hours of content bar chart">'
            . $svg
            . '</svg>'
            . '</div>';
    }

    public static function formatHours(float $seconds): string
    {
        if ($seconds <= 0) {
            return '0';
        }

        $hours = $seconds / 3600;

        return number_format($hours, $hours >= 100 ? 0 : 1);
    }

    private static function pieSlicePath(
        float $cx,
        float $cy,
        float $radius,
        float $startAngle,
        float $endAngle
    ): string {
        if ($endAngle - $startAngle >= 359.999) {
            return sprintf(
                'M %.2F,%.2F m -%.2F,0 a %.2F,%.2F 0 1,0 %.4F,0 a %.2F,%.2F 0 1,0 -%.4F,0',
                $cx,
                $cy,
                $radius,
                $radius,
                $radius,
                $radius * 2,
                $radius,
                $radius,
                $radius * 2
            );
        }

        $start = self::polarToCartesian($cx, $cy, $radius, $endAngle);
        $end   = self::polarToCartesian($cx, $cy, $radius, $startAngle);
        $large = ($endAngle - $startAngle) > 180 ? 1 : 0;

        return sprintf(
            'M %.2F,%.2F L %.2F,%.2F A %.2F,%.2F 0 %d,0 %.2F,%.2F Z',
            $cx,
            $cy,
            $start[0],
            $start[1],
            $radius,
            $radius,
            $large,
            $end[0],
            $end[1]
        );
    }

    /** @return array{0: float, 1: float} */
    private static function polarToCartesian(float $cx, float $cy, float $radius, float $angleDeg): array
    {
        $rad = deg2rad($angleDeg - 90);

        return [
            round($cx + ($radius * cos($rad)), 2),
            round($cy + ($radius * sin($rad)), 2),
        ];
    }
}
