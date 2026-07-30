<?php

declare(strict_types=1);

use MediaManager\Support\AppVersion;
use PHPUnit\Framework\TestCase;

final class AppVersionTest extends TestCase
{
    public function test_current_reads_version_file(): void
    {
        $version = AppVersion::current();
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $version);
        $this->assertNotSame('0.0.0', $version);
    }

    public function test_changelog_entries_include_initial_release(): void
    {
        $entries = AppVersion::changelogEntries(5);
        $this->assertNotEmpty($entries);
        $this->assertSame('0.2.0', $entries[0]['version']);
        $this->assertSame('2026-07-30', $entries[0]['date']);
        $this->assertStringContainsString('Queue unapprove', $entries[0]['body']);
    }

    public function test_format_body_html_escapes_and_lists(): void
    {
        $html = AppVersion::formatBodyHtml("### Added\n\n- Item <script>\n- Second");
        $this->assertStringContainsString('<h3', $html);
        $this->assertStringContainsString('<ul', $html);
        $this->assertStringContainsString('Item &lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
