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

    /** @return array{imported: int, shows_created: int, skipped: list<string>, warnings: list<string>} */
    public function importFile(string $path, bool $replaceExisting = true): array
    {
        $this->warnings = [];
        $this->skipped  = [];

        if (!is_readable($path)) {
            throw new \RuntimeException('Schedule CSV not readable: ' . $path);
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open schedule CSV.');
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new \RuntimeException('Empty schedule CSV.');
        }

        $columns = array_flip(array_map(static fn ($h) => strtolower(trim((string) $h)), $header));
        $required = ['show_title', 'time_slot_et', 'days', 'premiere_date'];
        foreach ($required as $col) {
            if (!isset($columns[$col])) {
                fclose($handle);
                throw new \RuntimeException('Missing CSV column: ' . $col);
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

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === false) {
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
            $effectiveFrom = $this->parseDate($get('premiere_date'));
            if ($effectiveFrom === null) {
                $this->skipped[] = 'Invalid premiere date: ' . $title;
                continue;
            }
            $effectiveTo = $this->parseDate($get('end_date'));

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

        fclose($handle);

        return [
            'imported'       => $imported,
            'shows_created'  => $showsCreated,
            'skipped'        => $this->skipped,
            'warnings'       => $this->warnings,
        ];
    }

    /** @param array<string, string> $titleToShowId */
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

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
}
