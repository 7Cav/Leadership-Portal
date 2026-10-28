<?php

use App\Models\ArtifactType;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateUnchangedException;
use App\Repositories\TemplateVersionRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

// Story 3.5, #375 / AD-17: versions are immutable rows per template, a template's active pointer is one column that
// moves, and a company's active template is one choice row. The migration seeds the regiment default, so every test
// starts with that one template and its one version.

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
        ->and(DB::table('template_versions')->whereNotNull('company_id')->count())->toBe(2);
});

it('keeps exactly one active pointer per company and kind however many versions there are', function () {
    foreach (['a', 'b', 'c'] as $body) {
        $this->repo->author($this->company->id, $this->type, $body, $this->author->id);
    }

    expect(DB::table('templates')->where('company_id', $this->company->id)->count())->toBe(1)
        ->and(DB::table('unit_template_choices')->where('unit_id', $this->company->id)->count())->toBe(1);
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
        ->and(TemplateVersion::query()->whereNotNull('company_id')->count())->toBe(0);
});

it('moves the pointer back to an older version without writing a new one or touching either row', function () {
    $first = $this->repo->author($this->company->id, $this->type, 'first body', $this->author->id);
    $second = $this->repo->author($this->company->id, $this->type, 'second body', $this->author->id);
    $rows = DB::table('template_versions')->orderBy('id')->get();

    $activated = $this->repo->activate($first, $this->author->id);

    expect($activated->id)->toBe($first->id)
        ->and($this->repo->activeFor($this->company->id, $this->type)->id)->toBe($first->id)
        ->and(DB::table('template_versions')->orderBy('id')->get())->toEqual($rows)
        ->and(DB::table('templates')->where('company_id', $this->company->id)->value('active_version_id'))->toBe($first->id)
        ->and($second->version)->toBe(2);
});

it('activates a version for its own company only, since the pointer takes both from the version', function () {
    $mine = $this->repo->author($this->company->id, $this->type, 'mine', $this->author->id);
    $other = Unit::factory()->create(['type' => UnitType::Company]);
    $theirs = $this->repo->author($other->id, $this->type, 'theirs', $this->author->id);
    $this->repo->author($this->company->id, $this->type, 'mine, edited', $this->author->id);

    $this->repo->activate($mine, $this->author->id);

    expect($this->repo->activeFor($this->company->id, $this->type)->id)->toBe($mine->id)
        ->and($this->repo->activeFor($other->id, $this->type)->id)->toBe($theirs->id);
});

it('refuses to activate a version that no longer exists', function () {
    $version = $this->repo->author($this->company->id, $this->type, 'body', $this->author->id);
    $ghost = new TemplateVersion;
    $ghost->forceFill(['id' => $version->id + 999, 'template_id' => $version->template_id, 'company_id' => $this->company->id, 'artifact_type' => $this->type]);

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
        ->and(DB::table('template_versions')->whereNotNull('company_id')->pluck('version')->sort()->values()->all())->toBe([1, 2])
        ->and($this->repo->activeFor($this->company->id, $this->type)->id)->toBe($mine->id);
});

it('gives up after a few attempts rather than loop forever', function () {
    $this->repo->author($this->company->id, $this->type, 'first', $this->author->id);
    TemplateVersion::creating(fn (TemplateVersion $row) => $row->version = 1);

    expect(fn () => $this->repo->author($this->company->id, $this->type, 'mine', $this->author->id))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a duplicate version number for one template at the data layer', function () {
    $first = $this->repo->author($this->company->id, $this->type, 'first', $this->author->id);

    expect(fn () => DB::table('template_versions')->insert([
        'template_id' => $first->template_id,
        'company_id' => $this->company->id,
        'artifact_type' => $this->type->value,
        'version' => 1,
        'body' => 'duplicate',
        'created_by' => $this->author->id,
        'created_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('stores a body at the length limit made of four-byte characters, which a 64KB text column could not hold', function () {
    $body = str_repeat('😀', 20000);

    $version = $this->repo->author($this->company->id, $this->type, $body, $this->author->id);

    expect(mb_strlen($this->repo->find($version->id)->body))->toBe(20000)
        ->and(strlen($body))->toBeGreaterThan(65535);
});

it('names what may be mass-assigned on a version, so nothing else can be set through the model', function () {
    $version = new TemplateVersion(['company_id' => 1, 'version' => 9, 'body' => 'x', 'created_by' => 1, 'id' => 77, 'created_at' => '2000-01-01']);

    expect($version->id)->toBeNull()->and($version->getAttribute('created_at'))->toBeNull()->and($version->version)->toBe(9);
});

it('refuses to activate the version that is already active, writing nothing', function () {
    $version = $this->repo->author($this->company->id, $this->type, 'body', $this->author->id);

    expect(fn () => $this->repo->activate($version, $this->author->id))->toThrow(TemplateUnchangedException::class);
});

it('never deletes a version through the model', function () {
    $version = $this->repo->author($this->company->id, $this->type, 'body', $this->author->id);

    expect(fn () => $version->delete())->toThrow(LogicException::class)
        ->and(TemplateVersion::query()->where('template_id', $version->template_id)->count())->toBe(1);
});

describe('named templates (#375)', function () {
    it('numbers each template from 1, so two templates of one company never share a line of versions', function () {
        $first = $this->repo->createTemplate($this->company->id, $this->type, 'Standard', 'a', $this->author->id);
        $second = $this->repo->createTemplate($this->company->id, $this->type, 'Training', 'b', $this->author->id);
        $edit = $this->repo->authorVersion($second, 'b2', $this->author->id);

        expect($first->activeVersion->version)->toBe(1)
            ->and($second->activeVersion->version)->toBe(1)
            ->and($edit->version)->toBe(2)
            ->and($edit->template_id)->toBe($second->id)
            ->and($edit->company_id)->toBe($this->company->id)
            // The first template is the company's active one; the second leaves it alone.
            ->and($this->repo->activeFor($this->company->id, $this->type)->id)->toBe($first->active_version_id)
            ->and($this->repo->history($this->company->id, $this->type)->pluck('body')->all())->toBe(['a'])
            ->and($this->repo->historyOf($second)->pluck('body')->all())->toBe(['b2', 'b']);
    });

    it('authors "the company template" as its active one, whichever that is', function () {
        $this->repo->createTemplate($this->company->id, $this->type, 'Standard', 'a', $this->author->id);
        $training = $this->repo->createTemplate($this->company->id, $this->type, 'Training', 'b', $this->author->id);
        $this->repo->choose($this->company, $training, $this->author->id);

        $version = $this->repo->author($this->company->id, $this->type, 'b2', $this->author->id);

        expect($version->template_id)->toBe($training->id)->and($version->version)->toBe(2);
    });

    it('switches a template on and off, refusing a switch that changes nothing', function () {
        $template = $this->repo->createTemplate($this->company->id, $this->type, 'Standard', 'a', $this->author->id);

        expect($this->repo->setEnabled($template, false)->enabled)->toBeFalse()
            ->and(fn () => $this->repo->setEnabled($template, false))->toThrow(TemplateUnchangedException::class);
    });

    it('keeps one choice per unit and kind, replacing it, refusing a repeat, and clears it', function () {
        $a = $this->repo->createTemplate($this->company->id, $this->type, 'A', 'a', $this->author->id);
        $b = $this->repo->createTemplate($this->company->id, $this->type, 'B', 'b', $this->author->id);
        $section = Unit::factory()->create(['type' => UnitType::Section]);

        $this->repo->choose($section, $a, $this->author->id);
        $this->repo->choose($section, $b, $this->author->id);

        expect(DB::table('unit_template_choices')->where('unit_id', $section->id)->count())->toBe(1)
            ->and($this->repo->choiceFor($section->id, $this->type)->template_id)->toBe($b->id)
            ->and(fn () => $this->repo->choose($section, $b, $this->author->id))->toThrow(TemplateUnchangedException::class);

        $this->repo->clearChoice($section, $this->type);

        expect($this->repo->choiceFor($section->id, $this->type))->toBeNull()
            ->and(fn () => $this->repo->clearChoice($section, $this->type))->toThrow(TemplateUnchangedException::class);
    });

    it('edits the regiment default under its own row lock, since it has no company', function () {
        $default = $this->repo->regimentDefault($this->type);

        $version = $this->repo->authorVersion($default, 'regiment v2', $this->author->id);

        expect($version->company_id)->toBeNull()
            ->and($version->version)->toBe(2)
            ->and($this->repo->regimentDefault($this->type)->active_version_id)->toBe($version->id)
            ->and($this->repo->templatesOf($this->company->id, $this->type))->toHaveCount(0);
    });

    it('says whether a company holds any template, for the delete guard', function () {
        expect($this->repo->hasTemplatesFor($this->company))->toBeFalse();

        $this->repo->createTemplate($this->company->id, $this->type, 'A', 'a', $this->author->id);

        expect($this->repo->hasTemplatesFor($this->company))->toBeTrue();
    });
});
