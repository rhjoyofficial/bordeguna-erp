<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;

/**
 * Audit #26, 14 Oct 2026: the browser security headers on every page.
 */
it('sends the framing, sniffing and referrer headers on a page, and HSTS only over https', function () {
    $this->get('/login')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    // Behind Traefik: TLS is terminated there and announced by the forwarded proto.
    $this->withHeader('X-Forwarded-Proto', 'https')->get('/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('sends them on an authenticated ERP page and on a JSON answer too', function () {
    $business = Business::create(['name' => 'Header Co', 'slug' => 'header-co']);
    $user = User::factory()->create();
    $user->businesses()->attach($business->id);

    $this->actingAs($user)->withSession(['current_business_id' => $business->id])
        ->get('/dashboard')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

    $this->getJson('/up')->assertHeader('X-Content-Type-Options', 'nosniff');
});
