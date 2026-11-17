<?php

namespace Tests\Unit;

use App\Modules\Routine\Models\RoutineBlock;
use PHPUnit\Framework\TestCase;

class RoutineBlockTest extends TestCase
{
    public function test_detects_blocks_crossing_midnight(): void
    {
        $this->assertTrue((new RoutineBlock(['start_time' => '23:00', 'end_time' => '07:00']))->crossesMidnight());
        $this->assertFalse((new RoutineBlock(['start_time' => '19:00', 'end_time' => '21:00']))->crossesMidnight());
    }
}
