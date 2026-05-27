<?php

declare(strict_types=1);

namespace MediaManager\Tests;

use MediaManager\Services\ProposalFilenameParser;
use PHPUnit\Framework\TestCase;

final class ProposalFilenameParserTest extends TestCase
{
    public function testParseStandardFilename(): void
    {
        $parsed = ProposalFilenameParser::parseFilename('ABRAMS_20211214_2000_PGM.mp4');
        $this->assertSame('ABRAMS', $parsed['show_abbr']);
        $this->assertSame('20211214', $parsed['date']);
        $this->assertSame('2000', $parsed['time']);
        $this->assertSame('PGM', $parsed['media_token']);
    }

    public function testDetectTemplatePlaceholder(): void
    {
        $this->assertTrue(ProposalFilenameParser::isTemplate(
            '/NNN/YYYY/MM/Program/',
            'NNN_YYYYMMDD_HHMM_PGM.mp4'
        ));
        $this->assertFalse(ProposalFilenameParser::isTemplate(
            '/ABRAMS/2021/12/Program/',
            'ABRAMS_20211214_2000_PGM.mp4'
        ));
    }

    public function testParseDir(): void
    {
        $parsed = ProposalFilenameParser::parseDir('ABRAMS/2021/12/Program');
        $this->assertSame('ABRAMS', $parsed['show_abbr']);
        $this->assertSame('2021', $parsed['year']);
        $this->assertSame('12', $parsed['month']);
        $this->assertSame('Program', $parsed['folder_type']);
    }
}
