<?php

declare(strict_types=1);

use MediaManager\Services\ScheduleCsvImporter;
use PHPUnit\Framework\TestCase;

final class ScheduleCsvImporterTest extends TestCase
{
    public function test_excel_serial_to_ymd(): void
    {
        $this->assertSame('2020-09-01', ScheduleCsvImporter::excelSerialToYmd(44075));
        $this->assertSame('2021-03-01', ScheduleCsvImporter::excelSerialToYmd(44256));
        $this->assertSame('2021-07-18', ScheduleCsvImporter::excelSerialToYmd(44395));
        $this->assertSame('2022-03-25', ScheduleCsvImporter::excelSerialToYmd(44645));
        $this->assertSame('2026-06-06', ScheduleCsvImporter::excelSerialToYmd(46179));
        $this->assertSame('2026-06-07', ScheduleCsvImporter::excelSerialToYmd(46180.75));
    }

    public function test_parse_schedule_date_formats(): void
    {
        $this->assertSame('2020-09-01', ScheduleCsvImporter::parseScheduleDate('2020-09-01'));
        $this->assertSame('2020-09-01', ScheduleCsvImporter::parseScheduleDate('20200901'));
        $this->assertSame('2020-09-01', ScheduleCsvImporter::parseScheduleDate('9/1/2020'));
        $this->assertSame('2020-09-01', ScheduleCsvImporter::parseScheduleDate('44075'));
        $this->assertSame('2021-07-18', ScheduleCsvImporter::parseScheduleDate('44395.0'));
        $this->assertNull(ScheduleCsvImporter::parseScheduleDate(''));
        $this->assertNull(ScheduleCsvImporter::parseScheduleDate('not-a-date'));
        $this->assertNull(ScheduleCsvImporter::parseScheduleDate('100')); // out of range serial
    }
}
