<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Resolve file date/time from filename vs FFprobe with low default confidence.
 */
final class FileDateTimeResolver
{
    /** @return array{date: ?string, time: ?string, signals: list<string>, datetime_confidence: string} */
    public static function resolve(string $filename, ?array $ffprobe): array
    {
        $signals = [];
        $fromFile = DateNormalizer::fromFilename($filename);
        $fileDate = $fromFile['date'];
        $fileTime = $fromFile['time'];
        if ($fromFile['signal'] !== null) {
            $signals[] = $fromFile['signal'];
        }

        $probeDate = null;
        $probeTime = null;
        if ($ffprobe !== null && !empty($ffprobe['creation_time'])) {
            $fromProbe = DateNormalizer::fromFfprobe((string) $ffprobe['creation_time']);
            $probeDate = $fromProbe['date'];
            $probeTime = $fromProbe['time'];
            if ($fromProbe['signal'] !== null) {
                $signals[] = $fromProbe['signal'];
            }
        }

        $date = $fileDate;
        $time = $fileTime;

        if ($probeTime !== null) {
            $time = $probeTime;
            $signals[] = 'datetime:time from ffprobe (preferred)';
        } elseif ($fileTime !== null) {
            $signals[] = 'datetime:time from filename (LOW)';
        }

        if ($probeDate !== null && $fileDate === null) {
            $date = $probeDate;
            $signals[] = 'datetime:date from ffprobe only (LOW)';
        } elseif ($fileDate !== null) {
            $date = $fileDate;
            if ($probeDate !== null && $probeDate !== $fileDate) {
                $signals[] = 'datetime:date filename vs ffprobe disagree — using filename (LOW)';
            }
        }

        if ($fileTime !== null && $probeTime !== null && $fileTime !== $probeTime) {
            $delta = self::timeDeltaMinutes($fileTime, $probeTime);
            if ($delta !== null && abs($delta) > 30) {
                $signals[] = sprintf(
                    'datetime:filename vs ffprobe time disagree by %d min — review',
                    abs($delta)
                );
            } elseif ($delta !== null && abs($delta) > 15) {
                $signals[] = 'datetime:filename vs ffprobe time differ slightly';
            }
        }

        $confidence = 'LOW';
        if ($date !== null && $time !== null) {
            if ($probeTime !== null && $fileTime !== null && $fileTime === $probeTime) {
                $confidence = 'MEDIUM';
                $signals[] = 'datetime:sources agree (MEDIUM)';
            } elseif ($probeTime !== null) {
                $confidence = 'MEDIUM';
            }
        }

        return [
            'date'                 => $date,
            'time'                 => $time,
            'signals'              => $signals,
            'datetime_confidence'  => $confidence,
        ];
    }

    private static function timeDeltaMinutes(string $a, string $b): ?int
    {
        if (strlen($a) < 4 || strlen($b) < 4) {
            return null;
        }
        $am = (int) substr($a, 0, 2) * 60 + (int) substr($a, 2, 2);
        $bm = (int) substr($b, 0, 2) * 60 + (int) substr($b, 2, 2);

        return $am - $bm;
    }
}
