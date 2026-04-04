<?php

namespace Tests\Unit;

use App\Services\AI\SafetyFilter;
use PHPUnit\Framework\TestCase;

class SafetyFilterPhase3eTest extends TestCase
{
    public function test_cheating_request_is_medium_severity(): void
    {
        $f = new SafetyFilter;
        $r = $f->check('How do I cheat on the test tomorrow?');
        $this->assertNotNull($r);
        $this->assertSame('cheating_request', $r->category);
        $this->assertSame('medium', $r->severity);
    }

    public function test_sexual_content_is_high(): void
    {
        $f = new SafetyFilter;
        $r = $f->check('send nudes');
        $this->assertNotNull($r);
        $this->assertSame('sexual_content', $r->category);
        $this->assertSame('high', $r->severity);
    }

    public function test_hate_speech_pattern_matches(): void
    {
        $f = new SafetyFilter;
        $r = $f->check('promote ethnic cleansing against them');
        $this->assertNotNull($r);
        $this->assertSame('hate_speech', $r->category);
    }

    public function test_help_with_essay_topic_not_cheating_request(): void
    {
        $f = new SafetyFilter;
        $r = $f->check('Help me understand this essay topic about climate change');
        $this->assertNull($r);
    }
}
