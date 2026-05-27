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
        if (!preg_match('/<sheetData>(.*)<\/sheetData>/s', $xml, $sheetMatch)) {
            return [];
        }

        $rows = [];
        $rowChunks = preg_split('/<\/row>/', $sheetMatch[1]) ?: [];
        foreach ($rowChunks as $chunk) {
            if (!preg_match('/<row[^>]*>(.*)$/s', $chunk, $rowMatch)) {
                continue;
            }

            $cells = [];
            if (preg_match_all('/<c r="([A-Z]+)(\d+)"([^>]*)>(.*?)<\/c>/s', $rowMatch[1], $cellMatches, PREG_SET_ORDER)) {
                foreach ($cellMatches as $cell) {
                    $col = self::columnIndex($cell[1]);
                    $cells[$col] = self::cellValue($cell[3], $cell[4], $shared);
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

    /** @param list<string> $shared */
    private static function cellValue(string $attrs, string $body, array $shared): string
    {
        if (preg_match('/<v>(.*?)<\/v>/s', $body, $valueMatch)) {
            $value = $valueMatch[1];
            if (str_contains($attrs, ' t="s"') && is_numeric($value)) {
                return $shared[(int) $value] ?? '';
            }

            return html_entity_decode((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        if (preg_match_all('/<t(?:[^>]*)>([^<]*)<\/t>/', $body, $textMatches)) {
            return html_entity_decode(implode('', $textMatches[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return '';
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
