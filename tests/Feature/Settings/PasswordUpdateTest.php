<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/password');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/settings/password');
    }

    /**
     * ⚠⚠ Audit, 13 Oct 2026: changing the password left every other session
     * alive, so whoever had the old one stayed signed in.
     */
    public function test_changing_the_password_ends_every_other_session_of_that_user()
    {
        // phpunit.xml runs the array driver, where session rows cannot exist.
        config(['session.driver' => 'database']);

        $user = User::factory()->create();
        $other = User::factory()->create();
        $user->setRememberToken('token-before-change');
        $user->save();

        foreach ([[$user, 'stolen-laptop'], [$other, 'colleague']] as [$owner, $id]) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $owner->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
                'payload' => base64_encode(serialize([])), 'last_activity' => now()->getTimestamp(),
            ]);
        }

        $this->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(DB::table('sessions')->where('id', 'stolen-laptop')->exists());
        $this->assertTrue(DB::table('sessions')->where('id', 'colleague')->exists());
        $this->assertNotSame('token-before-change', $user->fresh()->getRememberToken());
        // The person who changed it is still signed in.
        $this->assertAuthenticatedAs($user);
    }
}
