<?php

namespace Tests\Unit;

use App\Actions\Classroom\ActionFactory;
use Tests\TestCase;

class ClassroomActionFactoryTest extends TestCase
{
    public function test_known_types_return_instance(): void
    {
        $a = ActionFactory::make('wb_open', []);
        $this->assertNotNull($a);
        $this->assertSame('wb_open', $a->type);
    }

    public function test_unknown_type_returns_null(): void
    {
        $this->assertNull(ActionFactory::make('not_an_action', []));
    }

    public function test_invalid_params_return_null(): void
    {
        $this->assertNull(ActionFactory::make('discussion', []));
    }
}
