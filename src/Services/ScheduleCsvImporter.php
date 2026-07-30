<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\ProgramScheduleRepository;
use MediaManager\Repositories\ShowRepository;

final class ScheduleCsvImporter
{
    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string> */
    private array $skipped = [];

    public function __construct(
        private readonly ProgramScheduleRepository $schedule = new ProgramScheduleRepository(),
        private readonly ShowRepository $shows = new ShowRepository(),
    ) {
    }

    /**
     * @param string|null $originalName Original upload filename (extension hint for tmp paths)
     * @return array{imported: int, shows_created: int, skipped: list<string>, warnings: list<string>}
     */
    public function importFile(string $path, bool $replaceExisting = true, ?string $originalName = null): array
    {
        $this->warnings = [];
        $this->skipped  = [];

        if (!is_readable($path)) {
            throw new \RuntimeException('Schedule file not readable: ' . $path);
        }

        $rows = $this->readRows($path, $originalName);
        if ($rows === []) {
            throw new \RuntimeException('Empty schedule file.');
        }

        $header = array_shift($rows);
        if ($header === null) {
            throw new \RuntimeException('Empty schedule file.');
        }

        $columns = array_flip(array_map(
            static fn ($h) => strtolower(trim(ltrim((string) $h, "\xEF\xBB\xBF"))),
            $header
        ));
        $required = ['show_title', 'time_slot_et', 'days', 'premiere_date'];
        foreach ($required as $col) {
            if (!isset($columns[$col])) {
                throw new \RuntimeException('Missing schedule column: ' . $col);
            }
        }

        if ($replaceExisting) {
            $this->schedule->deleteAll();
        }

        $existingAbbrevs = array_map(
            static fn (array $s): string => strtoupper((string) $s['abbreviation']),
            $this->shows->all()
        );
        $showsCreated = 0;
        $imported = 0;
        $titleToShowId = [];

        foreach ($rows as $row) {
            if ($row === [] || $row === [null]) {
                continue;
            }

            $get = static function (string $key) use ($columns, $row): string {
                if (!isset($columns[$key])) {
                    return '';
                }
                $idx = $columns[$key];

                return trim((string) ($row[$idx] ?? ''));
            };

            $title = $get('show_title');
            if ($title === '') {
                continue;
            }

            $notes = $get('notes');
            if (ScheduleTimeParser::isReplayNotes($notes)) {
                $this->skipped[] = 'Replay skipped: ' . $title;
                continue;
            }

            $slot = ScheduleTimeParser::parseTimeSlot($get('time_slot_et'));
            if ($slot === null) {
                $this->skipped[] = 'Invalid time slot: ' . $title;
                continue;
            }

            if (ScheduleTimeParser::isOvernightSpill($slot['start'], $slot['end'])) {
                $this->skipped[] = 'Overnight span skipped: ' . $title . ' (' . $get('time_slot_et') . ')';
                continue;
            }

            $blocks = ScheduleTimeParser::expandToHourlyBlocks($slot['start'], $slot['end']);
            if ($blocks === []) {
                $this->skipped[] = 'No hourly blocks: ' . $title;
                continue;
            }

            $daysMask = ScheduleTimeParser::parseDays($get('days'));
            $effectiveFrom = self::parseScheduleDate($get('premiere_date'));
            if ($effectiveFrom === null) {
                $this->skipped[] = 'Invalid premiere date: ' . $title . ' (' . $get('premiere_date') . ')';
                continue;
            }
            $effectiveTo = self::parseScheduleDate($get('end_date'));

            $showId = $this->resolveShowId($title, $titleToShowId, $existingAbbrevs, $showsCreated, $get);
            if ($showId === null) {
                $this->skipped[] = 'Could not create show: ' . $title;
                continue;
            }

            $sourceRowId = is_numeric($get('show_id')) ? (int) $get('show_id') : null;

            foreach ($blocks as $block) {
                $this->schedule->insert([
                    'show_id'         => $showId,
                    'source_row_id'   => $sourceRowId,
                    'title'           => $title,
                    'hour_start_et'   => ScheduleTimeParser::minutesToTime($block['start']),
                    'hour_end_et'     => ScheduleTimeParser::minutesToTime($block['end'], true),
                    'days_of_week'    => $daysMask,
                    'effective_from'  => $effectiveFrom,
                    'effective_to'    => $effectiveTo,
                    'era_name'        => $get('era_name'),
                    'anchor_names'    => $get('anchor_host'),
                    'show_type'       => $get('show_type'),
                    'network_brand'   => $get('network_brand'),
                    'notes'           => $notes,
                ]);
                $imported++;
            }
        }

        return [
            'imported'       => $imported,
            'shows_created'  => $showsCreated,
            'skipped'        => $this->skipped,
            'warnings'       => $this->warnings,
        ];
    }

    /**
     * Normalize schedule dates to Y-m-d.
     * Accepts ISO (YYYY-MM-DD), US (M/D/YYYY), YYYYMMDD, and Excel 1900-system serials.
     */
    public static function parseScheduleDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3])
                : null;
        }

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3])
                : null;
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m) === 1) {
            $month = (int) $m[1];
            $day   = (int) $m[2];
            $year  = (int) $m[3];

            return checkdate($month, $day, $year)
                ? sprintf('%04d-%02d-%02d', $year, $month, $day)
                : null;
        }

        // Excel serial (optionally fractional time-of-day)
        if (preg_match('/^\d+(\.\d+)?$/', $value) === 1) {
            $serial = (float) $value;
            // Reasonable broadcast-era window (~1980–2100)
            if ($serial < 29000 || $serial > 73000) {
                return null;
            }

            return self::excelSerialToYmd($serial);
        }

        return null;
    }

    /** Excel 1900 date system (Windows) → Y-m-d. */
    public static function excelSerialToYmd(float $serial): ?string
    {
        $days = (int) floor($serial);
        if ($days < 1) {
            return null;
        }

        try {
            // 1899-12-30 + N accounts for Excel's bogus 1900-02-29 leap day.
            $dt = (new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC')))
                ->modify('+' . $days . ' days');
        } catch (\Exception) {
            return null;
        }

        return $dt->format('Y-m-d');
    }

    /** @return list<list<string>> */
    private function readRows(string $path, ?string $originalName): array
    {
        if ($this->isXlsx($path, $originalName)) {
            return SimpleXlsxReader::readRows($path);
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open schedule CSV.');
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_map(static fn ($v) => trim((string) $v), $row);
        }
        fclose($handle);

        return $rows;
    }

    private function isXlsx(string $path, ?string $originalName): bool
    {
        $name = $originalName ?? $path;
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            return true;
        }
        if ($ext === 'csv' || $ext === 'txt') {
            return false;
        }

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $sig = fread($fh, 4);
        fclose($fh);

        return $sig === "PK\x03\x04";
    }

    /** @param array<string, int> $titleToShowId */
    /** @param list<string> $existingAbbrevs */
    private function resolveShowId(
        string $title,
        array &$titleToShowId,
        array &$existingAbbrevs,
        int &$showsCreated,
        callable $get,
    ): ?int {
        $key = strtolower(trim($title));
        if (isset($titleToShowId[$key])) {
            return $titleToShowId[$key];
        }

        $existing = $this->shows->findByCanonicalName($title);
        if ($existing !== null) {
            $titleToShowId[$key] = (int) $existing['id'];

            return $titleToShowId[$key];
        }

        $abbrev = ShowAbbreviationGenerator::fromTitle($title, $existingAbbrevs);
        $existingAbbrevs[] = $abbrev;

        try {
            $id = $this->shows->create(
                $title,
                $abbrev,
                [$title, strtolower($title)],
                'Auto-created from schedule import. ' . trim($get('notes'))
            );
            $showsCreated++;
            $titleToShowId[$key] = $id;

            return $id;
        } catch (\Throwable $e) {
            $this->warnings[] = 'Show create failed for ' . $title . ': ' . $e->getMessage();

            return null;
        }
    }
}
