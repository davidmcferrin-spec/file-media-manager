<?php

declare(strict_types=1);

use MediaManager\Services\ContinuityCheckService;
use PHPUnit\Framework\TestCase;

final class ContinuityCheckServiceTest extends TestCase
{
    public function test_agree_never_raises_above_rule_score(): void
    {
        $merged = ContinuityCheckService::mergeVerdict('MEDIUM', [
            'agree'      => true,
            'confidence' => 'HIGH',
            'show_id'    => 3,
        ]);
        $this->assertSame('MEDIUM', $merged['confidence']);
        $this->assertNull($merged['adopt_show_id']);
        $this->assertSame('continuity:confirmed', $merged['signal']);
    }

    public function test_disagree_drops_high_to_low(): void
    {
        $merged = ContinuityCheckService::mergeVerdict('HIGH', [
            'agree'      => false,
            'confidence' => 'MEDIUM',
            'show_id'    => 9,
        ]);
        $this->assertSame('LOW', $merged['confidence']);
        $this->assertSame(9, $merged['adopt_show_id']);
        $this->assertSame('continuity:conflict', $merged['signal']);
    }

    public function test_uncertain_blunts_high(): void
    {
        $merged = ContinuityCheckService::mergeVerdict('HIGH', [
            'confidence' => 'HIGH',
        ]);
        $this->assertSame('MEDIUM', $merged['confidence']);
        $this->assertSame('continuity:review', $merged['signal']);
    }

    public function test_agree_on_unevaluated_adopts_engine_score_and_show(): void
    {
        $merged = ContinuityCheckService::mergeVerdict('UNEVALUATED', [
            'agree'      => true,
            'confidence' => 'MEDIUM',
            'show_id'    => 3,
        ]);
        $this->assertSame('MEDIUM', $merged['confidence']);
        $this->assertSame(3, $merged['adopt_show_id']);
        $this->assertSame('continuity:confirmed', $merged['signal']);
    }

    public function test_agree_on_low_adopts_engine_show(): void
    {
        $merged = ContinuityCheckService::mergeVerdict('LOW', [
            'agree'      => true,
            'confidence' => 'MEDIUM',
            'show_id'    => 7,
        ]);
        $this->assertSame('LOW', $merged['confidence']);
        $this->assertSame(7, $merged['adopt_show_id']);
    }

    public function test_merge_datetime_fills_gaps(): void
    {
        $dt = ContinuityCheckService::mergeDateTime(null, null, [
            'file_date'      => '2024-07-15',
            'file_time'      => '1900',
            'datetime_agree' => true,
        ], 'HIGH');
        $this->assertSame('20240715', $dt['file_date']);
        $this->assertSame('1900', $dt['file_time']);
        $this->assertTrue($dt['changed']);
        $this->assertContains('continuity:date filled', $dt['signals']);
    }

    public function test_merge_datetime_keeps_strong_rule_on_conflict(): void
    {
        $dt = ContinuityCheckService::mergeDateTime('20240101', '0800', [
            'file_date'      => '20240715',
            'file_time'      => '1900',
            'datetime_agree' => true,
            'agree'          => true,
        ], 'HIGH');
        $this->assertSame('20240101', $dt['file_date']);
        $this->assertSame('0800', $dt['file_time']);
        $this->assertFalse($dt['changed']);
        $this->assertContains('continuity:date conflict', $dt['signals']);
    }

    public function test_merge_datetime_adopts_on_disagree(): void
    {
        $dt = ContinuityCheckService::mergeDateTime('20240101', '0800', [
            'file_date' => '20240715',
            'file_time' => '1900',
            'agree'     => false,
        ], 'HIGH');
        $this->assertSame('20240715', $dt['file_date']);
        $this->assertSame('1900', $dt['file_time']);
        $this->assertTrue($dt['changed']);
        $this->assertContains('continuity:date adopted', $dt['signals']);
    }

    public function test_merge_media_type_fills_gap(): void
    {
        [$typesById, $idsByAbbr] = $this->mediaTypeFixtures();
        $mt = ContinuityCheckService::mergeMediaType(null, null, [
            'media_type_id'    => 2,
            'media_type_agree' => true,
        ], $typesById, $idsByAbbr, 'HIGH');
        $this->assertSame(2, $mt['media_type_id']);
        $this->assertSame('CLN', $mt['media_type_abbreviation']);
        $this->assertTrue($mt['changed']);
        $this->assertContains('continuity:media type filled', $mt['signals']);
    }

    public function test_merge_media_type_keeps_strong_rule_on_conflict(): void
    {
        [$typesById, $idsByAbbr] = $this->mediaTypeFixtures();
        $mt = ContinuityCheckService::mergeMediaType(1, 'PGM', [
            'media_type'       => 'CLN',
            'media_type_agree' => true,
            'agree'            => true,
        ], $typesById, $idsByAbbr, 'HIGH');
        $this->assertSame(1, $mt['media_type_id']);
        $this->assertSame('PGM', $mt['media_type_abbreviation']);
        $this->assertFalse($mt['changed']);
        $this->assertContains('continuity:media type conflict', $mt['signals']);
    }

    public function test_merge_media_type_adopts_on_weak_rule(): void
    {
        [$typesById, $idsByAbbr] = $this->mediaTypeFixtures();
        $mt = ContinuityCheckService::mergeMediaType(1, 'PGM', [
            'media_type_id'    => 2,
            'media_type_agree' => true,
            'agree'            => true,
        ], $typesById, $idsByAbbr, 'LOW');
        $this->assertSame(2, $mt['media_type_id']);
        $this->assertSame('CLN', $mt['media_type_abbreviation']);
        $this->assertTrue($mt['changed']);
        $this->assertContains('continuity:media type adopted', $mt['signals']);
    }

    public function test_prefer_engine_on_disagree_or_weak_rules(): void
    {
        $this->assertTrue(ContinuityCheckService::preferEngineProposal('HIGH', ['agree' => false]));
        $this->assertTrue(ContinuityCheckService::preferEngineProposal('LOW', ['agree' => true]));
        $this->assertFalse(ContinuityCheckService::preferEngineProposal('HIGH', ['agree' => true]));
    }

    /** @return array{0: array<int, array{id: int, abbreviation: string, name: string, folder_name: string}>, 1: array<string, int>} */
    private function mediaTypeFixtures(): array
    {
        $typesById = [
            1 => ['id' => 1, 'abbreviation' => 'PGM', 'name' => 'Program', 'folder_name' => 'Program'],
            2 => ['id' => 2, 'abbreviation' => 'CLN', 'name' => 'Clean', 'folder_name' => 'Clean'],
        ];
        $idsByAbbr = [
            'PGM'     => 1,
            'CLN'     => 2,
            'PROGRAM' => 1,
            'CLEAN'   => 2,
        ];

        return [$typesById, $idsByAbbr];
    }
}
