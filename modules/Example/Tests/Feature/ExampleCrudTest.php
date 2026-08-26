<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Example\HookConstants;
use Modules\Example\Models\Example;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Sanctum::actingAs(User::factory()->create());
});

it('lists examples paginated', function (): void {
    Example::factory()->count(3)->create();

    getJson('/api/v1/examples')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(3, 'data');
});

it('creates an example and fires the created hook', function (): void {
    $fired = false;
    add_action(HookConstants::CREATED, function () use (&$fired): void {
        $fired = true;
    });

    postJson('/api/v1/examples', [
        'title' => 'My First Example',
        'body' => 'Hello world',
        'status' => 'published',
        'is_featured' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'my-first-example')
        ->assertJsonPath('data.status', 'published');

    expect($fired)->toBeTrue();
    expect(Example::where('slug', 'my-first-example')->exists())->toBeTrue();
});

it('auto-deduplicates slugs', function (): void {
    Example::factory()->create(['slug' => 'duplicate']);

    postJson('/api/v1/examples', ['title' => 'Duplicate'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'duplicate-2');
});

it('shows a single example', function (): void {
    $example = Example::factory()->create();

    getJson("/api/v1/examples/{$example->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $example->id);
});

it('updates an example', function (): void {
    $example = Example::factory()->create(['title' => 'Old']);

    putJson("/api/v1/examples/{$example->id}", ['title' => 'New Title'])
        ->assertOk()
        ->assertJsonPath('data.title', 'New Title');
});

it('soft-deletes an example', function (): void {
    $example = Example::factory()->create();

    deleteJson("/api/v1/examples/{$example->id}")->assertOk();

    expect(Example::withTrashed()->where('id', $example->id)->first()?->trashed())->toBeTrue();
});

it('stores and reads model meta', function (): void {
    $example = Example::factory()->create();
    $example->setMeta('external_id', 'abc-123');

    expect($example->getMeta('external_id'))->toBe('abc-123');
});
