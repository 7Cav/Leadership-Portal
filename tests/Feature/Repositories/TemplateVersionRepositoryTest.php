<?php

use App\Models\ArtifactType;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateVersionRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Story 3.5 / AD-17: versions are immutable rows per (company, artifact type) and the active pointer is one row that moves.

beforeEach(function () {
    $this->repo = app(TemplateVersionRepository::class);
    $this->company = Unit::factory()->create(['type' => UnitType::Company]);
    $this->author = User::factory()->create();
    $this->type = ArtifactType::RollcallBbcode;
});

it('writes the first version as 1 and makes it the active one', function () {
    $version = $this->repo->author($this->company->id, $this->type, 'first body', $this->author->id);

    expect($version->version)->toBe(1)
        ->and($version->body)->toBe('first body')
        ->and($version->created_by)->toBe($this->author->id)
        ->and($this->repo->activeFor($this->company->id, $this->type)->id)->toBe($version->id);
});

it('writes each edit as the next version and moves the pointer, leaving the earlier row exactly as it was', function () {
    $first = $this->repo->author($this->company->id, $this->type, 'first body', $this->author->id);
    $before = DB::table('template_versions')->where('id', $first->id)->first();

    $second = $this->repo->author($this->company->id, $this->type, 'second body', $this->author->id);

    expect($second->version)->toBe(2)
        ->and($this->repo->activeFor($this->company->id, $this->type)->id)->toBe($second->id)
        ->and(DB::table('template_versions')->where('id', $first->id)->first())->toEqual($before)
        ->and(DB::table('template_versions')->count())->toBe(2);
});

it('keeps exactly one active pointer per company and kind however many versions there are', function () {
    foreach (['a', 'b', 'c'] as $body) {
        $this->repo->author($this->company->id, $this->type, $body, $this->author->id);
    }

    expect(DB::table('active_template_versions')->count())->toBe(1);
});

it('refuses to update a version row, at the model as well as by never issuing one', function () {
    $version = $this->repo->author($this->company->id, $this->type, 'first body', $this->author->id);

    expect(fn () => $version->update(['body' => 'changed']))->toThrow(LogicException::class, 'immutable')
        ->and(fn () => TemplateVersion::query()->find($version->id)->forceFill(['body' => 'changed'])->save())->toThrow(LogicException::class)
        ->and(TemplateVersion::query()->find($version->id)->body)->toBe('first body');
});

it('numbers versions per company, so two companies never share or skip a number', function () {
    $other = Unit::factory()->create(['type' => UnitType::Company]);

    $this->repo->author($this->company->id, $this->type, 'a', $this->author->id);
    $this->repo->author($this->company->id, $this->type, 'b', $this->author->id);
    $theirs = $this->repo->author($other->id, $this->type, 'x', $this->author->id);

    expect($theirs->version)->toBe(1)
        ->and($this->repo->activeFor($other->id, $this->type)->body)->toBe('x')
        ->and($this->repo->activeFor($this->company->id, $this->type)->body)->toBe('b');
});

it('returns null for a company that has never authored a template', function () {
    expect($this->repo->activeFor($this->company->id, $this->type))->toBeNull();
});

it('refuses a unit that is not a company', function () {
    $section = Unit::factory()->create(['type' => UnitType::Section]);

    expect(fn () => $this->repo->author($section->id, $this->type, 'body', $this->author->id))->toThrow(InvalidArgumentException::class, 'not a company')
        ->and(TemplateVersion::query()->count())->toBe(0);
});

it('moves the pointer back to an older version without writing a new one or touching either row', function () {
    $first = $this->repo->author($this->company->id, $this->type, 'first body', $this->author->id);
    $second = $this->repo->author($this->company->id, $this->type, 'second body', $this->author->id);
    $rows = DB::table('template_versions')->orderBy('id')->get();

    $activated = $this->repo->activate($first, $this->author->id);

    expect($activated->id)->toBe($first->id)
        ->and($this->repo->activeFor($this->company->id, $this->type)->id)->toBe($first->id)
        ->and(DB::table('template_versions')->orderBy('id')->get())->toEqual($rows)
        ->and(DB::table('active_template_versions')->count())->toBe(1)
        ->and($second->version)->toBe(2);
});

it('activates a version for its own company only, since the pointer takes both from the version', function () {
    $mine = $this->repo->author($this->company->id, $this->type, 'mine', $this->author->id);
    $other = Unit::factory()->create(['type' => UnitType::Company]);
    $theirs = $this->repo->author($other->id, $this->type, 'theirs', $this->author->id);

    $this->repo->activate($mine, $this->author->id);

    expect($this->repo->activeFor($other->id, $this->type)->id)->toBe($theirs->id);
});

it('refuses to activate a version that no longer exists', function () {
    $version = $this->repo->author($this->company->id, $this->type, 'body', $this->author->id);
    $ghost = new TemplateVersion;
    $ghost->forceFill(['id' => $version->id + 999, 'company_id' => $this->company->id, 'artifact_type' => $this->type]);

    expect(fn () => $this->repo->activate($ghost, $this->author->id))->toThrow(InvalidArgumentException::class);
});

it('lists a company\'s history newest first, each with its author loaded', function () {
    foreach (['a', 'b', 'c'] as $body) {
        $this->repo->author($this->company->id, $this->type, $body, $this->author->id);
    }
    $this->repo->author(Unit::factory()->create(['type' => UnitType::Company])->id, $this->type, 'x', $this->author->id);

    $history = $this->repo->history($this->company->id, $this->type);

    expect($history->pluck('version')->all())->toBe([3, 2, 1])
        // `preventLazyLoading()` is on outside production: reading `author` would throw if it were not loaded.
        ->and($history->every(fn (TemplateVersion $v) => $v->author->id === $this->author->id))->toBeTrue();
});

it('finds a version by id', function () {
    $version = $this->repo->author($this->company->id, $this->type, 'body', $this->author->id);

    expect($this->repo->find($version->id)->body)->toBe('body')
        ->and($this->repo->find($version->id + 999))->toBeNull();
});

it('retries with the next free number when its first number was already taken, keeping both versions', function () {
    $this->repo->author($this->company->id, $this->type, 'first', $this->author->id);
    // A racing author committed version 2 after we read the highest number, so our first attempt holds a stale
    // number. Simulate that stale read by forcing the first attempt's number to one that is already taken.
    $forced = false;
    TemplateVersion::creating(function (TemplateVersion $row) use (&$forced) {
        if (! $forced) {
            $forced = true;
            $row->version = 1;
        }
    });

    $mine = $this->repo->author($this->company->id, $this->type, 'mine', $this->author->id);

    expect($mine->version)->toBe(2)
        ->and(DB::table('template_versions')->pluck('version')->sort()->values()->all())->toBe([1, 2])
        ->and($this->repo->activeFor($this->company->id, $this->type)->id)->toBe($mine->id);
});

it('gives up after a few attempts rather than loop forever', function () {
    $this->repo->author($this->company->id, $this->type, 'first', $this->author->id);
    TemplateVersion::creating(fn (TemplateVersion $row) => $row->version = 1);

    expect(fn () => $this->repo->author($this->company->id, $this->type, 'mine', $this->author->id))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a duplicate version number for one company and kind at the data layer', function () {
    $this->repo->author($this->company->id, $this->type, 'first', $this->author->id);

    expect(fn () => DB::table('template_versions')->insert([
        'company_id' => $this->company->id,
        'artifact_type' => $this->type->value,
        'version' => 1,
        'body' => 'duplicate',
        'created_by' => $this->author->id,
        'created_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});
