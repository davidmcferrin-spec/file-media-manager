<?php

declare(strict_types=1);

use MediaManager\Services\ProposalPathBuilder;
use PHPUnit\Framework\TestCase;

final class ProposalPathBuilderTest extends TestCase
{
    public function test_build_standard_name(): void
    {
        $built = ProposalPathBuilder::build(
            'CUOMO',
            '20240715',
            '1900',
            'Clean',
            'Clean',
            'clip.mxf'
        );

        $this->assertNotNull($built);
        $this->assertSame('CUOMO/2024/07/Clean', $built['proposed_dir']);
        $this->assertSame('CUOMO_20240715_1900_Clean.mxf', $built['proposed_filename']);
    }

    public function test_build_giso_with_guest(): void
    {
        $built = ProposalPathBuilder::build(
            'BANFIELD',
            '20240101',
            '0800',
            'GISO',
            'ISO',
            'guest.mp4',
            'Jane_Doe'
        );

        $this->assertNotNull($built);
        $this->assertSame('BANFIELD/2024/01/ISO', $built['proposed_dir']);
        $this->assertSame('BANFIELD_20240101_0800_GISO_Jane_Doe.mp4', $built['proposed_filename']);
    }

    public function test_normalize_date_input(): void
    {
        $this->assertSame('20240715', ProposalPathBuilder::normalizeDateInput('2024-07-15'));
        $this->assertSame('20240715', ProposalPathBuilder::normalizeDateInput('20240715'));
        $this->assertNull(ProposalPathBuilder::normalizeDateInput(''));
        $this->assertNull(ProposalPathBuilder::normalizeDateInput('2024-13-01'));
    }

    public function test_guest_from_proposed(): void
    {
        $this->assertSame(
            'Jane_Doe',
            ProposalPathBuilder::guestFromProposed('BANFIELD_20240101_0800_GISO_Jane_Doe.mp4')
        );
        $this->assertNull(ProposalPathBuilder::guestFromProposed('CUOMO_20240715_1900_Clean.mxf'));
    }
}
