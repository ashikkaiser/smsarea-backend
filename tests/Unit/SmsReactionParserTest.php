<?php

namespace Tests\Unit;

use App\Support\SmsReactionParser;
use PHPUnit\Framework\TestCase;

class SmsReactionParserTest extends TestCase
{
    public function test_parses_loved_with_straight_quotes(): void
    {
        $r = SmsReactionParser::parse('Loved "hello"');
        $this->assertNotNull($r);
        $this->assertSame('love', $r['type']);
        $this->assertSame('add', $r['action']);
        $this->assertSame('hello', $r['target']);
    }

    public function test_parses_loved_with_markdown_bold_stars(): void
    {
        $r = SmsReactionParser::parse('Loved **hello**');
        $this->assertNotNull($r);
        $this->assertSame('love', $r['type']);
        $this->assertSame('hello', $r['target']);
    }

    public function test_parses_love_present_tense(): void
    {
        $r = SmsReactionParser::parse('Love "hi"');
        $this->assertNotNull($r);
        $this->assertSame('love', $r['type']);
        $this->assertSame('hi', $r['target']);
    }

    public function test_does_not_match_unquoted_sentence(): void
    {
        $this->assertNull(SmsReactionParser::parse('Loved someone today'));
    }

    public function test_parses_custom_reacted_to_unquoted_target(): void
    {
        $r = SmsReactionParser::parse('Reacted 😂 to party time');
        $this->assertNotNull($r);
        $this->assertSame('add', $r['action']);
        $this->assertSame('party time', $r['target']);
    }
}
