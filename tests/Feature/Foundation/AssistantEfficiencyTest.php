<?php

use App\Models\User;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\ErpAssistant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * What an off-topic question costs, and what stops it costing more.
 *
 * ⚠⚠ AN IRRELEVANT QUESTION IS BILLED LIKE ANY OTHER. The whole system prompt
 * and every tool definition go up the wire before the model has decided the
 * question is not about the ERP. Two things keep that cheap, and both are
 * asserted here because neither is visible in the answer: the stable prefix is
 * marked cacheable, and the model is told not to call a tool before declining.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Cost Co', 'slug' => 'cost-co']);
    app(BusinessContext::class)->set($this->business->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->business->id);

    Permission::firstOrCreate(['name' => 'inventory.view', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'Storeman', 'business_id' => $this->business->id, 'guard_name' => 'web'])
        ->givePermissionTo('inventory.view');

    $this->user = User::factory()->create();
    $this->user->businesses()->attach($this->business->id);
    $this->user->assignRole('Storeman');

    $this->assistant = app(ErpAssistant::class);
});

it('marks the stable part of the prompt cacheable, and only the stable part', function () {
    // ⚠⚠ Caching is a PREFIX match, so the breakpoint has to sit on the last
    // block that is byte-identical between requests. The static block is;
    // the per-user block is not, and the language line changes with the toggle.
    // A breakpoint on a volatile block caches nothing and looks like it works.
    $blocks = $this->assistant->systemBlocks($this->user);

    expect($blocks[0])->toHaveKey('cacheControl')
        ->and($blocks[0]['cacheControl'])->toBe(['type' => 'ephemeral']);

    foreach (array_slice($blocks, 1) as $block) {
        expect($block)->not->toHaveKey('cacheControl');
    }
});

it('keeps the cacheable block byte-identical between two different people', function () {
    // ⚠ If anything user-specific leaked into the static block, every person
    // would have their own cache and the hit rate would quietly be zero.
    $other = User::factory()->create();
    $other->businesses()->attach($this->business->id);

    $mine = $this->assistant->systemBlocks($this->user)[0]['text'];
    $theirs = $this->assistant->systemBlocks($other)[0]['text'];

    expect($mine)->toBe($theirs);
});

it('tells the model not to look anything up before declining an off-topic question', function () {
    // ⚠⚠ THE SAVING THAT MATTERS. Without this the model may call read_data
    // while working out that the question is not about the ERP - a whole extra
    // round trip, billed, that tells the person nothing.
    $prompt = $this->assistant->systemBlocks($this->user)[0]['text'];

    expect($prompt)->toContain('NEVER CALL A TOOL');
});

it('asks for a short, warm decline rather than a lecture', function () {
    $prompt = $this->assistant->systemBlocks($this->user)[0]['text'];

    expect($prompt)->toContain('ONE short, friendly sentence')
        ->and($prompt)->toContain('never a lecture')
        // ⚠ Output tokens are billed too, and a restated question doubles the
        // length of a refusal that should be one line.
        ->and($prompt)->toContain('Do not restate the question');
});

it('sends no tool definitions at all to somebody with no AI grants', function () {
    // ⚠ The cheapest request is the one carrying nothing it cannot use. Without
    // the grants the tools are simply not sent, so an off-topic question from
    // somebody who cannot use tools costs the prompt and nothing more.
    $tools = app(\App\Modules\Foundation\Support\Ai\AssistantTools::class)->definitions($this->user);

    expect($tools)->toBe([]);
});
