<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        // ⚠ The business attachment is not incidental. This test used to create
        // a bare user and assert 200, which quietly encoded the W5b defect: a
        // user belonging to NO business was shown a dashboard that rendered
        // empty and correct, with nothing saying why. They are now sent to the
        // switcher, which explains it - see BusinessSwitchReturnTest and §14.2.
        $business = Business::create(['name' => 'Dashboard Co', 'slug' => 'dashboard-co']);

        $user = User::factory()->create();
        $user->businesses()->attach($business->id);

        $this->actingAs($user);

        $this->get('/dashboard')->assertOk();
    }
}
