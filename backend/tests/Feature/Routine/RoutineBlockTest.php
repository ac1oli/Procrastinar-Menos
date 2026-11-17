<?php

namespace Tests\Feature\Routine;

use App\Models\User;
use App\Modules\Routine\Models\RoutineBlock;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshDatabase;
use Tests\TestCase;

class RoutineBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_creates_block_with_sorted_days(): void
    {
        $this->postJson('/api/routine-blocks', [
            'title' => 'Estudo de Python',
            'category' => 'study',
            'days_of_week' => [5, 1, 3],
            'start_time' => '19:00',
            'end_time' => '21:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.days_of_week', [1, 3, 5])
            ->assertJsonPath('data.start_time', '19:00')
            ->assertJsonPath('data.crosses_midnight', false)
            ->assertJsonPath('data.is_active', true);

        $this->assertSame(1, $this->user->routineBlocks()->count());
    }

    public function test_supports_blocks_crossing_midnight(): void
    {
        $this->postJson('/api/routine-blocks', [
            'title' => 'Sono',
            'category' => 'rest',
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7],
            'start_time' => '23:00',
            'end_time' => '07:00',
        ])
            ->assertCreated()
            ->assertJsonPath('data.crosses_midnight', true);
    }

    public function test_validates_input(): void
    {
        $this->postJson('/api/routine-blocks', [
            'title' => '',
            'category' => 'procrastinar',
            'days_of_week' => [0, 8, 8],
            'start_time' => '25:00',
            'end_time' => '10:00',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'category', 'days_of_week.0', 'days_of_week.1', 'start_time']);
    }

    public function test_rejects_equal_start_and_end(): void
    {
        $this->postJson('/api/routine-blocks', [
            'title' => 'Zero minutos',
            'category' => 'other',
            'days_of_week' => [1],
            'start_time' => '10:00',
            'end_time' => '10:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_time');
    }

    public function test_partial_update_compares_with_stored_times(): void
    {
        $block = RoutineBlock::factory()->for($this->user)->create(['start_time' => '19:00', 'end_time' => '21:00']);

        $this->patchJson("/api/routine-blocks/{$block->id}", ['end_time' => '19:00'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_time');

        $this->patchJson("/api/routine-blocks/{$block->id}", ['end_time' => '22:30', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.end_time', '22:30')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.title', $block->title);
    }

    public function test_lists_only_own_blocks_ordered_by_start(): void
    {
        RoutineBlock::factory()->for($this->user)->create(['start_time' => '20:00', 'end_time' => '21:00']);
        RoutineBlock::factory()->for($this->user)->create(['start_time' => '08:00', 'end_time' => '09:00']);
        RoutineBlock::factory()->count(2)->create();

        $this->getJson('/api/routine-blocks')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.start_time', '08:00');
    }

    public function test_cannot_access_blocks_of_another_user(): void
    {
        $foreign = RoutineBlock::factory()->create();

        $this->getJson("/api/routine-blocks/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/routine-blocks/{$foreign->id}", ['title' => 'x'])->assertNotFound();
        $this->deleteJson("/api/routine-blocks/{$foreign->id}")->assertNotFound();

        $this->assertModelExists($foreign);
    }

    public function test_deletes_own_block(): void
    {
        $block = RoutineBlock::factory()->for($this->user)->create();

        $this->deleteJson("/api/routine-blocks/{$block->id}")->assertNoContent();

        $this->assertModelMissing($block);
    }
}
