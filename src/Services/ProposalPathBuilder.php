<?php

declare(strict_types=1);

namespace MediaManager\Services;

/**
 * Build policy-shaped proposed_dir / proposed_filename from show + date + time + type.
 */
final class ProposalPathBuilder
{
    /**
     * @return array{proposed_dir: string, proposed_filename: string}|null
     */
    public static function build(
        string $showAbbr,
        string $dateYyyymmdd,
        ?string $timeHhmm,
        string $typeAbbr,
        string $folderType,
        string $originalFilename,
        ?string $guestName = null
    ): ?array {
        $showAbbr = strtoupper(trim($showAbbr));
        $typeAbbr = trim($typeAbbr);
        $folderType = trim($folderType);
        $dateYyyymmdd = trim($dateYyyymmdd);

        if ($showAbbr === '' || $typeAbbr === '' || $folderType === '') {
            return null;
        }
        if (!DateNormalizer::isValidDate($dateYyyymmdd)) {
            return null;
        }

        $time = '0000';
        if ($timeHhmm !== null && $timeHhmm !== '') {
            $normalized = DateNormalizer::normalizeTime($timeHhmm);
            if ($normalized === null) {
                return null;
            }
            $time = $normalized;
        }

        $year  = substr($dateYyyymmdd, 0, 4);
        $month = substr($dateYyyymmdd, 4, 2);
        $proposedDir = $showAbbr . '/' . $year . '/' . $month . '/' . $folderType;

        $ext = MediaExtensions::extension($originalFilename);
        if ($ext === '') {
            return null;
        }

        $guest = $guestName !== null ? trim($guestName) : '';
        if ($guest !== '' && strtoupper($typeAbbr) === 'GISO') {
            $proposedFilename = sprintf(
                '%s_%s_%s_GISO_%s.%s',
                $showAbbr,
                $dateYyyymmdd,
                $time,
                $guest,
                $ext
            );
        } else {
            $proposedFilename = sprintf(
                '%s_%s_%s_%s.%s',
                $showAbbr,
                $dateYyyymmdd,
                $time,
                $typeAbbr,
                $ext
            );
        }

        return [
            'proposed_dir'      => $proposedDir,
            'proposed_filename' => $proposedFilename,
        ];
    }

    /** Guest segment from an existing proposed filename, if GISO-shaped. */
    public static function guestFromProposed(?string $proposedFilename): ?string
    {
        if ($proposedFilename === null || $proposedFilename === '') {
            return null;
        }
        $parsed = ProposalFilenameParser::parseFilename($proposedFilename);

        return $parsed['guest'];
    }

    /** Accept YYYYMMDD or YYYY-MM-DD; return YYYYMMDD or null if invalid/empty. */
    public static function normalizeDateInput(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) === 1) {
            $ymd = $m[1] . $m[2] . $m[3];

            return DateNormalizer::isValidDate($ymd) ? $ymd : null;
        }
        if (DateNormalizer::isValidDate($raw)) {
            return $raw;
        }

        return null;
    }
}
