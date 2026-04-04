<?php

namespace Tests\Unit;

use App\Services\AI\ResponseParser;
use PHPUnit\Framework\TestCase;

class ResponseParserTest extends TestCase
{
    public function test_plain_text_becomes_single_segment(): void
    {
        $p = new ResponseParser;
        $out = $p->parse("Hello\nworld");

        $this->assertSame([
            ['type' => 'text', 'content' => "Hello\nworld"],
        ], $out);
    }

    public function test_image_tag(): void
    {
        $p = new ResponseParser;
        $out = $p->parse("Intro\n[IMAGE:water cycle]\nMore");

        $this->assertSame('text', $out[0]['type']);
        $this->assertSame('image', $out[1]['type']);
        $this->assertSame('water cycle', $out[1]['keyword']);
        $this->assertSame('text', $out[2]['type']);
    }

    public function test_quiz_requires_answer_in_options(): void
    {
        $p = new ResponseParser;
        $out = $p->parse('[QUIZ:Q?|A|B|C|D]');

        $this->assertSame([], $out);
    }

    public function test_valid_quiz(): void
    {
        $p = new ResponseParser;
        $out = $p->parse('[QUIZ:2+2?|3|4|5|4]');

        $this->assertSame('quiz', $out[0]['type']);
        $this->assertSame('2+2?', $out[0]['question']);
        $this->assertSame(['3', '4', '5'], $out[0]['options']);
        $this->assertSame('4', $out[0]['answer']);
    }

    public function test_drops_tags_beyond_two(): void
    {
        $p = new ResponseParser;
        $out = $p->parse("[IMAGE:a]\n[IMAGE:b]\n[IMAGE:c]\n");

        $nonText = array_values(array_filter($out, fn ($s) => $s['type'] !== 'text'));
        $this->assertCount(2, $nonText);
        $this->assertSame('a', $nonText[0]['keyword']);
        $this->assertSame('b', $nonText[1]['keyword']);
    }
}
