<?php

declare(strict_types=1);

namespace MediaManager\Services;

use MediaManager\Repositories\LegacyRenameMapRepository;
use MediaManager\Repositories\ShowRepository;

final class LegacyRenameMapImporter
{
    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string> */
    private array $skipped = [];

    public function __construct(
        private readonly LegacyRenameMapRepository $map = new LegacyRenameMapRepository(),
        private readonly ShowRepository $shows = new ShowRepository(),
        private readonly MediaTypeResolver $mediaTypes = new MediaTypeResolver(),
    ) {
    }

    /** @return array{imported: int, skipped: list<string>, warnings: list<string>} */
    public function importFile(string $path, bool $replaceExisting = true): array
    {
        $this->warnings = [];
        $this->skipped  = [];

        $rows = $this->readRows($path);
        if ($rows === []) {
            throw new \RuntimeException('Spreadsheet is empty.');
        }

        $headerLine = 1;
        $header = $this->findHeaderRow($rows, $headerLine);
        if ($header === null) {
            throw new \RuntimeException('Missing header row.');
        }

        $columns = $this->mapColumns($header);
        $required = ['source', 'original path', 'original filename', 'show abbr', 'media type'];
        $missing = array_values(array_filter($required, static fn (string $col): bool => !isset($columns[$col])));
        if ($missing !== []) {
            $found = array_keys($columns);
            sort($found);
            throw new \RuntimeException(
                'Missing column(s): ' . implode(', ', $missing)
                . '. Found: ' . ($found !== [] ? implode(', ', $found) : '(none)')
            );
        }

        if ($replaceExisting) {
            $this->map->deleteAll();
        }

        $imported = 0;
        foreach ($rows as $lineNum => $row) {
            $line = $headerLine + $lineNum + 1;
            $get = static function (string $key) use ($columns, $row): string {
                if (!isset($columns[$key])) {
                    return '';
                }
                $idx = $columns[$key];

                return trim((string) ($row[$idx] ?? ''));
            };

            $source = strtoupper($get('source'));
            $origPath = $get('original path');
            $origFile = $get('original filename');
            if ($source === '' || $origPath === '' || $origFile === '') {
                $this->skipped[] = "Line {$line}: missing source, path, or filename";
                continue;
            }

            $suggestedPath = $get('suggested path');
            $suggestedFile = $get('suggested filename');
            $showAbbr = strtoupper($get('show abbr'));
            $mediaLabel = $get('media type');
            $notes = $get('notes');
            $confidence = (int) preg_replace('/\D/', '', $get('confidence (1–10)')) ?: (int) preg_replace('/\D/', '', $get('confidence'));
            if ($confidence < 1 || $confidence > 10) {
                $confidence = 5;
            }

            $isTemplate = ProposalFilenameParser::isTemplate($suggestedPath, $suggestedFile);
            $show = $showAbbr !== '' ? $this->shows->findByAbbreviation($showAbbr) : null;
            if ($show === null && $showAbbr !== '') {
                $this->warnings[] = "Line {$line}: show abbreviation not in dictionary: {$showAbbr}";
            }

            $mediaType = $mediaLabel !== '' ? $this->mediaTypes->resolve($mediaLabel) : null;
            if ($mediaType === null && $mediaLabel !== '') {
                $this->warnings[] = "Line {$line}: unknown media type: {$mediaLabel}";
            }

            $targetDir = null;
            $targetFile = null;
            if (!$isTemplate && $suggestedPath !== '' && $suggestedFile !== '') {
                $normalized = $this->mediaTypes->normalizeProposal($suggestedPath, $suggestedFile, $mediaLabel);
                if ($normalized === null) {
                    $this->skipped[] = "Line {$line}: could not normalize suggested path/filename";
                    continue;
                }
                $targetDir = $normalized['dir'];
                $targetFile = $normalized['filename'];
                if ($mediaType === null && $normalized['media_type_id'] !== null) {
                    $mediaType = ['id' => $normalized['media_type_id']];
                }
            }

            $matchPath = trim(str_replace('\\', '/', $origPath), '/');
            if ($isTemplate && $suggestedPath === '' && $suggestedFile === '') {
                $this->skipped[] = "Line {$line}: template row without suggested targets";
                continue;
            }

            try {
                $this->map->insert([
                    'source_label'       => $source,
                    'match_path'         => $matchPath,
                    'match_filename'     => $origFile,
                    'target_dir'         => $targetDir,
                    'target_filename'    => $targetFile,
                    'show_id'            => $show['id'] ?? null,
                    'show_abbr'          => $showAbbr,
                    'media_type_id'      => $mediaType['id'] ?? null,
                    'media_type_label'   => $mediaLabel,
                    'curator_confidence' => $confidence,
                    'row_type'           => $isTemplate ? 'template' : 'concrete',
                    'notes'              => $notes,
                    'active'             => true,
                ]);
                $imported++;
            } catch (\PDOException $e) {
                if (str_contains($e->getMessage(), 'legacy_rename_map_source_path_file_key')) {
                    $this->skipped[] = "Line {$line}: duplicate path/filename for {$source}";
                } else {
                    throw $e;
                }
            }
        }

        return [
            'imported' => $imported,
            'skipped'  => $this->skipped,
            'warnings' => $this->warnings,
        ];
    }

    /** @return list<list<string>> */
    private function readRows(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'xlsx') {
            return SimpleXlsxReader::readRows($path);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Cannot open file.');
        }

        $delimiter = $this->detectCsvDelimiter($raw);
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open file.');
        }

        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_map(static fn ($v) => trim((string) $v), $row);
        }
        fclose($handle);

        return $rows;
    }

    /** @param list<list<string>> $rows @return list<string>|null */
    private function findHeaderRow(array &$rows, int &$headerLine): ?array
    {
        while ($rows !== []) {
            $candidate = array_shift($rows);
            if ($candidate === null) {
                $headerLine++;
                continue;
            }

            $columns = $this->mapColumns($candidate);
            if (isset($columns['source'], $columns['original path'], $columns['original filename'])) {
                return $candidate;
            }

            $first = strtolower(trim(str_replace(['–', '—'], '-', (string) ($candidate[0] ?? ''))));
            if (str_contains($first, 'summary') && !isset($columns['original path'])) {
                throw new \RuntimeException(
                    'This looks like the Summary sheet. Import the "Rename Map" sheet (columns: Source, Original Path, Original Filename, …).'
                );
            }

            $headerLine++;
        }

        return null;
    }

    private function detectCsvDelimiter(string $raw): string
    {
        $line = strtok($raw, "\r\n");
        if ($line === false || $line === '') {
            return ',';
        }

        $line = ltrim($line, "\xEF\xBB\xBF");
        $candidates = [',', ';', "\t"];
        $best = ',';
        $bestCount = 0;
        foreach ($candidates as $delimiter) {
            $count = substr_count($line, $delimiter);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $delimiter;
            }
        }

        return $best;
    }

    /** @param list<string> $header @return array<string, int> */
    private function mapColumns(array $header): array
    {
        $columns = [];
        foreach ($header as $i => $label) {
            $key = $this->normalizeHeaderLabel((string) $label);
            if ($key === '') {
                continue;
            }
            $columns[$key] = $i;
            if (str_starts_with($key, 'confidence')) {
                $columns['confidence (1-10)'] = $i;
                $columns['confidence (1–10)'] = $i;
                $columns['confidence'] = $i;
            }
        }

        $this->applyColumnAliases($columns);

        return $columns;
    }

    private function normalizeHeaderLabel(string $label): string
    {
        $label = ltrim($label, "\xEF\xBB\xBF");
        $label = strtolower(trim(str_replace(['–', '—'], '-', $label)));

        return $label;
    }

    /** @param array<string, int> $columns */
    private function applyColumnAliases(array &$columns): void
    {
        $aliases = [
            'source'            => ['src', 'source code', 'source_code', 'nas', 'site', 'origin'],
            'original path'     => ['original dir', 'original directory', 'legacy path', 'path'],
            'original filename' => ['original file', 'original name', 'legacy filename', 'filename', 'file name'],
            'suggested path'    => ['suggested dir', 'target path', 'target dir', 'proposed path'],
            'suggested filename'=> ['suggested file', 'target filename', 'target file', 'proposed filename'],
            'show abbr'         => ['show', 'show abbreviation', 'show_abbrev', 'show_abbr'],
            'media type'        => ['media', 'type', 'media_type'],
            'notes'             => ['note', 'comment', 'comments'],
        ];

        foreach ($aliases as $canonical => $names) {
            if (isset($columns[$canonical])) {
                continue;
            }
            foreach ($names as $alias) {
                if (isset($columns[$alias])) {
                    $columns[$canonical] = $columns[$alias];
                    break;
                }
            }
        }
    }
}
