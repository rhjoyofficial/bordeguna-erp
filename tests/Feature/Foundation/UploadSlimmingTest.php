<?php

use App\Models\User;
use App\Modules\Foundation\Actions\StoreAttachment;
use App\Modules\Foundation\Models\Business;
use App\Modules\Foundation\Support\BusinessContext;
use App\Modules\Foundation\Support\ImageSlimmer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Upload slimming (Joy, 3 Sep 2026): "we will not take any raw files - we
 * try to resize them, remove extra things, so its size reduces."
 *
 * Every image is slimmed ON THE WAY IN: downscaled to a sane maximum edge
 * and re-encoded. A phone photo of a bill keeps its readable numbers and
 * loses its megabytes. PNG stays PNG (transparency survives - signatures
 * and logos depend on it); anything that is not an image, or that would
 * come out BIGGER, passes through untouched. Slimming never throws: a file
 * GD cannot read is stored as it came.
 */
beforeEach(function () {
    $this->business = Business::create(['name' => 'Slim Co', 'slug' => 'slim-co']);
    app(BusinessContext::class)->set($this->business->id);

    Storage::fake('local');

    $this->user = User::factory()->create();
});

it('shrinks an oversized photo to the maximum edge and fewer bytes', function () {
    $original = UploadedFile::fake()->image('bill.jpg', 4000, 3000);
    $originalBytes = filesize($original->getRealPath());

    $attachment = app(StoreAttachment::class)->handle(
        attachable: $this->business,
        file: $original,
        collection: 'docs',
        uploadedBy: $this->user,
    );

    $stored = Storage::disk('local')->get($attachment->path);
    [$width, $height] = getimagesizefromstring($stored);

    expect(max($width, $height))->toBeLessThanOrEqual(1600)
        ->and(strlen($stored))->toBeLessThan($originalBytes)
        ->and($attachment->size)->toBe(strlen($stored));
});

it('keeps PNG as PNG so transparency survives', function () {
    $file = UploadedFile::fake()->image('sign.png', 2400, 800);

    $attachment = app(StoreAttachment::class)->handle(
        attachable: $this->business,
        file: $file,
        collection: 'signature',
        uploadedBy: $this->user,
        singleton: true,
    );

    $stored = Storage::disk('local')->get($attachment->path);
    $info = getimagesizefromstring($stored);

    expect($info['mime'])->toBe('image/png')
        ->and(max($info[0], $info[1]))->toBeLessThanOrEqual(1600);
});

it('passes a small PNG and a non-image through untouched, and never grows a JPEG', function () {
    // A small PNG is already as slim as it should be - the very same object
    // comes back, untouched.
    $png = UploadedFile::fake()->image('tiny.png', 300, 200);
    expect(ImageSlimmer::slim($png))->toBe($png);

    // A non-image is never touched.
    $pdf = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    expect(ImageSlimmer::slim($pdf))->toBe($pdf);

    // A small JPEG MAY be re-encoded (phone JPEGs benefit even at size),
    // but never at the cost of dimensions or MORE bytes.
    $small = UploadedFile::fake()->image('tiny.jpg', 300, 200);
    $smallBytes = filesize($small->getRealPath());
    $slimmed = ImageSlimmer::slim($small);
    [$width, $height] = getimagesize($slimmed->getRealPath());

    expect([$width, $height])->toBe([300, 200])
        ->and(filesize($slimmed->getRealPath()))->toBeLessThanOrEqual($smallBytes)
        ->and($slimmed->getClientOriginalName())->toBe('tiny.jpg');
});
