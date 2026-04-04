<?php

namespace Tests\Unit;

use App\Services\Safety\CrisisDetector;
use PHPUnit\Framework\TestCase;

class CrisisDetectorTest extends TestCase
{
    public function test_detects_self_harm_phrase(): void
    {
        $d = new CrisisDetector;
        $r = $d->detect('I want to hurt myself');
        $this->assertTrue($r->detected);
        $this->assertSame('self_harm', $r->type);
    }

    public function test_detects_immediate_danger(): void
    {
        $d = new CrisisDetector;
        $r = $d->detect('There is someone with a gun at school');
        $this->assertTrue($r->detected);
        $this->assertSame('immediate_danger', $r->type);
    }

    public function test_clean_message_not_crisis(): void
    {
        $d = new CrisisDetector;
        $r = $d->detect('What is photosynthesis?');
        $this->assertFalse($r->detected);
    }
}
