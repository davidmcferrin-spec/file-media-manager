<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Minimal XLSX writer (single sheet + shared strings). No external dependencies.
 */
final class SimpleXlsxWriter
{
    /**
     * @param list<list<string|int|float|null>> $rows
     */
    public static function writeToString(array $rows, string $sheetName = 'Sheet1'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create temp file for XLSX.');
        }

        try {
            self::writeToFile($tmp, $rows, $sheetName);
            $bytes = file_get_contents($tmp);
            if ($bytes === false) {
                throw new \RuntimeException('Cannot read generated XLSX.');
            }

            return $bytes;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param list<list<string|int|float|null>> $rows
     */
    public static function writeToFile(string $path, array $rows, string $sheetName = 'Sheet1'): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create XLSX archive.');
        }

        $shared = [];
        $sharedIndex = [];
        $sheetRowsXml = '';
        $rowNum = 1;

        foreach ($rows as $row) {
            $cellsXml = '';
            $col = 0;
            foreach ($row as $value) {
                $ref = self::columnLetters($col) . $rowNum;
                $text = self::stringify($value);
                if ($text === '') {
                    $col++;
                    continue;
                }

                if (is_int($value) || (is_float($value) && is_finite($value))) {
                    $cellsXml .= '<c r="' . $ref . '"><v>' . self::xmlNumber($value) . '</v></c>';
                } elseif (is_string($value) && is_numeric($value) && !preg_match('/^0\d+/', $value)) {
                    $cellsXml .= '<c r="' . $ref . '"><v>' . self::xmlEscape((string) $value) . '</v></c>';
                } else {
                    if (!isset($sharedIndex[$text])) {
                        $sharedIndex[$text] = count($shared);
                        $shared[] = $text;
                    }
                    $cellsXml .= '<c r="' . $ref . '" t="s"><v>' . $sharedIndex[$text] . '</v></c>';
                }
                $col++;
            }
            $sheetRowsXml .= '<row r="' . $rowNum . '">' . $cellsXml . '</row>';
            $rowNum++;
        }

        $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            . count($shared) . '" uniqueCount="' . count($shared) . '">';
        foreach ($shared as $s) {
            $sharedXml .= '<si><t xml:space="preserve">' . self::xmlEscape($s) . '</t></si>';
        }
        $sharedXml .= '</sst>';

        $sheetName = self::safeSheetName($sheetName);
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>' . $sheetRowsXml . '</sheetData>'
            . '</worksheet>';

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::relsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelsXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->close();
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private static function xmlNumber(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0';
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function columnLetters(int $index): string
    {
        $index++;
        $letters = '';
        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)) . $letters;
            $index = intdiv($index, 26);
        }

        return $letters;
    }

    private static function safeSheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\/\*\?\:\[\]]/', '', $name) ?? 'Sheet1';
        $name = trim($name);
        if ($name === '') {
            $name = 'Sheet1';
        }

        return mb_substr($name, 0, 31);
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function relsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookXml(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xmlEscape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private static function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="1"><xf xfId="0"/></cellXfs>'
            . '</styleSheet>';
    }
}
