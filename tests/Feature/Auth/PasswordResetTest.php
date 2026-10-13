<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Foundation\Notifications\SetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered()
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, SetPasswordNotification::class);
    }

    public function test_reset_password_screen_can_be_rendered()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, SetPasswordNotification::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, SetPasswordNotification::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    /**
     * ⚠⚠ Audit, 13 Oct 2026: a reset left every session signed in with the
     * old password alive.
     */
    public function test_resetting_the_password_ends_every_session_of_that_user()
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create();
        $other = User::factory()->create();

        foreach ([[$user, 'stolen-laptop'], [$other, 'colleague']] as [$owner, $id]) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $owner->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test',
                'payload' => base64_encode(serialize([])), 'last_activity' => now()->getTimestamp(),
            ]);
        }

        $this->post('/reset-password', [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertTrue(DB::table('sessions')->where('id', 'colleague')->exists());
    }
}
