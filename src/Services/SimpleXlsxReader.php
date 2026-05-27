<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Minimal XLSX reader (first sheet + shared strings). No external dependencies.
 */
final class SimpleXlsxReader
{
    /** @return list<list<string>> */
    public static function readRows(string $path): array
    {
        if (!is_readable($path)) {
            throw new \RuntimeException('XLSX not readable: ' . $path);
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Cannot open XLSX archive.');
        }

        $shared = self::readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new \RuntimeException('Missing sheet1 in XLSX.');
        }

        return self::parseSheet($sheetXml, $shared);
    }

    /** @return list<string> */
    private static function readSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $strings = [];
        if (preg_match_all('/<si>(.*?)<\/si>/s', $xml, $matches)) {
            foreach ($matches[1] as $block) {
                if (preg_match_all('/<t(?:[^>]*)>([^<]*)<\/t>/', $block, $parts)) {
                    $strings[] = html_entity_decode(implode('', $parts[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                } else {
                    $strings[] = '';
                }
            }
        }

        return $strings;
    }

    /** @param list<string> $shared @return list<list<string>> */
    private static function parseSheet(string $xml, array $shared): array
    {
        $rows = [];
        if (!preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $xml, $rowMatches)) {
            return [];
        }

        foreach ($rowMatches[1] as $rowXml) {
            $cells = [];
            if (preg_match_all('/<c r="([A-Z]+)(\d+)"([^>]*)>(?:<v>(.*?)<\/v>)?(?:<is>.*?<t[^>]*>(.*?)<\/t>.*?<\/is>)?<\/c>/s', $rowXml, $cellMatches, PREG_SET_ORDER)) {
                foreach ($cellMatches as $cell) {
                    $col = self::columnIndex($cell[1]);
                    $attrs = $cell[3];
                    $value = $cell[4] !== '' ? $cell[4] : ($cell[5] ?? '');
                    if (str_contains($attrs, ' t="s"') && is_numeric($value)) {
                        $value = $shared[(int) $value] ?? '';
                    }
                    $cells[$col] = html_entity_decode((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
            }
            if ($cells === []) {
                continue;
            }
            ksort($cells);
            $max = max(array_keys($cells));
            $row = [];
            for ($i = 0; $i <= $max; $i++) {
                $row[] = $cells[$i] ?? '';
            }
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return $index - 1;
    }
}
