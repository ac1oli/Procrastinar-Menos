<?php

namespace Tests\Feature\Goals;

use App\Models\User;
use App\Modules\Goals\Models\Goal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GoalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_creates_goal_as_active(): void
    {
        $this->postJson('/api/goals', [
            'title' => 'Tornar-me Data Analyst',
            'type' => 'professional',
            'target_date' => '2027-06-30',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.type', 'professional')
            ->assertJsonPath('data.target_date', '2027-06-30');
    }

    public function test_validates_input(): void
    {
        $this->postJson('/api/goals', ['title' => '', 'type' => 'fama', 'target_date' => '30/06/2027'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'type', 'target_date']);
    }

    public function test_updates_status(): void
    {
        $goal = Goal::factory()->for($this->user)->create();

        $this->patchJson("/api/goals/{$goal->id}", ['status' => 'paused'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paused')
            ->assertJsonPath('data.title', $goal->title);

        $this->patchJson("/api/goals/{$goal->id}", ['status' => 'desistido'])
            ->assertUnprocessable();
    }

    public function test_lists_only_own_goals(): void
    {
        Goal::factory()->for($this->user)->count(3)->create();
        Goal::factory()->count(2)->create();

        $this->getJson('/api/goals')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_cannot_access_goals_of_another_user(): void
    {
        $foreign = Goal::factory()->create();

        $this->getJson("/api/goals/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/goals/{$foreign->id}", ['status' => 'abandoned'])->assertNotFound();
        $this->deleteJson("/api/goals/{$foreign->id}")->assertNotFound();

        $this->assertModelExists($foreign);
    }

    public function test_deletes_own_goal(): void
    {
        $goal = Goal::factory()->for($this->user)->create();

        $this->deleteJson("/api/goals/{$goal->id}")->assertNoContent();

        $this->assertModelMissing($goal);
    }
}
