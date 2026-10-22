<?php

use App\Commands\Templates\ActivateTemplateVersion;
use App\Commands\Templates\AuthorTemplateVersion;
use App\Commands\Templates\InvalidTemplateException;
use App\Models\ArtifactType;
use App\Models\AuditLogEntry;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateUnchangedException;
use App\Repositories\TemplateVersionRepository;
use App\Support\Audit\AuditEnvelope;
use App\Support\Audit\ReasonRequiredException;
use Illuminate\Support\Collection;

/**
 * A repository whose first read of the active version still shows what a racing request saw before the other one
 * committed, so the Commands' cheap pre-check passes and only the repository's own check under its lock can stop
 * the second write.
 */
function staleActiveRepository(TemplateVersionRepository $real, ?TemplateVersion $seen): TemplateVersionRepository
{
    return new class($real, $seen) implements TemplateVersionRepository
    {
        private bool $firstRead = true;

        public function __construct(private TemplateVersionRepository $real, private ?TemplateVersion $seen) {}

        public function author(int $companyId, ArtifactType $type, string $body, int $createdBy): TemplateVersion
        {
            return $this->real->author($companyId, $type, $body, $createdBy);
        }

        public function activate(TemplateVersion $version, int $activatedBy): TemplateVersion
        {
            return $this->real->activate($version, $activatedBy);
        }

        public function activeFor(int $companyId, ArtifactType $type): ?TemplateVersion
        {
            if ($this->firstRead) {
                $this->firstRead = false;

                return $this->seen;
            }

            return $this->real->activeFor($companyId, $type);
        }

        public function history(int $companyId, ArtifactType $type): Collection
        {
            return $this->real->history($companyId, $type);
        }

        public function find(int $id): ?TemplateVersion
        {
            return $this->real->find($id);
        }

        public function hasTemplatesFor(Unit $company): bool
        {
            return $this->real->hasTemplatesFor($company);
        }
    };
}

// Story 3.5 / AD-16, AD-17: an edit is a new audited version with a reason, and a rollback only moves the pointer.

beforeEach(function () {
    $this->company = Unit::factory()->create(['type' => UnitType::Company]);
    $this->admin = User::factory()->admin()->create();
    $this->author = app(AuthorTemplateVersion::class);
    $this->activate = app(ActivateTemplateVersion::class);
    $this->repo = app(TemplateVersionRepository::class);
    $this->type = ArtifactType::RollcallBbcode;
});

describe('authoring', function () {
    it('creates version 1 for a company with no template, activates it and records who and why', function () {
        $version = $this->author->handle($this->admin, $this->company, $this->type, 'first body', 'First draft of the standard');

        $entry = AuditLogEntry::query()->sole();

        expect($version->version)->toBe(1)
            ->and($this->repo->activeFor($this->company->id, $this->type)->id)->toBe($version->id)
            ->and($entry->action)->toBe('template_version.authored')
            ->and($entry->actor_id)->toBe($this->admin->id)
            ->and($entry->reason)->toBe('First draft of the standard')
            ->and($entry->target_type)->toBe('TemplateVersion')
            ->and((int) $entry->target_id)->toBe($version->id);
    });

    it('writes an edit as a new version and leaves the earlier one unchanged', function () {
        $first = $this->author->handle($this->admin, $this->company, $this->type, 'first body', 'one');
        $second = $this->author->handle($this->admin, $this->company, $this->type, 'second body', 'two');

        expect($second->version)->toBe(2)
            ->and($this->repo->find($first->id)->body)->toBe('first body')
            ->and($this->repo->activeFor($this->company->id, $this->type)->body)->toBe('second body')
            ->and(AuditLogEntry::query()->count())->toBe(2);
    });

    it('requires a reason, and a rejected edit leaves no version and no log entry', function (?string $reason) {
        expect(fn () => $this->author->handle($this->admin, $this->company, $this->type, 'body', $reason))->toThrow(ReasonRequiredException::class);

        expect(TemplateVersion::query()->count())->toBe(0)
            ->and(AuditLogEntry::query()->count())->toBe(0);
    })->with([[null], [''], ['   ']]);

    it('refuses a unit that is not a company', function () {
        $platoon = Unit::factory()->create(['type' => UnitType::Platoon]);

        expect(fn () => $this->author->handle($this->admin, $platoon, $this->type, 'body', 'why'))->toThrow(InvalidTemplateException::class, 'not a company')
            ->and(TemplateVersion::query()->count())->toBe(0);
    });

    it('refuses an empty or whitespace-only body', function (string $body) {
        expect(fn () => $this->author->handle($this->admin, $this->company, $this->type, $body, 'why'))->toThrow(InvalidTemplateException::class, 'needs some text');
    })->with(['', '   ', "\n\t\n"]);

    it('refuses a body over the length limit, and accepts one exactly at it', function () {
        $atLimit = str_repeat('a', AuthorTemplateVersion::MAX_BODY_LENGTH);

        expect(fn () => $this->author->handle($this->admin, $this->company, $this->type, $atLimit.'a', 'why'))->toThrow(InvalidTemplateException::class, 'at most');

        expect($this->author->handle($this->admin, $this->company, $this->type, $atLimit, 'why')->version)->toBe(1);
    });

    it('refuses a body whose placeholders are unknown or unbalanced, and writes nothing', function (string $body) {
        expect(fn () => $this->author->handle($this->admin, $this->company, $this->type, $body, 'why'))->toThrow(InvalidTemplateException::class);
        expect(TemplateVersion::query()->count())->toBe(0);
    })->with(['an unknown name' => '{{colour}}', 'an unclosed loop' => '{{#troopers}}{{name}}', 'a row value outside the loop' => '{{name}}']);

    it('reports a bad template against the body field', function () {
        $thrown = null;

        try {
            $this->author->handle($this->admin, $this->company, $this->type, '{{colour}}', 'why');
        } catch (InvalidTemplateException $e) {
            $thrown = $e;
        }

        expect($thrown)->not->toBeNull()->and($thrown->field)->toBe('body')->and($thrown->getMessage())->toBe('Unknown name "colour".');
    });

    it('treats saving the active text again as a no-op: no new version, no log entry', function () {
        $first = $this->author->handle($this->admin, $this->company, $this->type, 'same body', 'one');
        $again = $this->author->handle($this->admin, $this->company, $this->type, 'same body', 'two');

        expect($again->id)->toBe($first->id)
            ->and(TemplateVersion::query()->count())->toBe(1)
            ->and(AuditLogEntry::query()->count())->toBe(1);
    });

    it('stops a double submit that raced past the pre-check: the repository decides under its lock, so no second version and no second log entry', function () {
        $first = $this->author->handle($this->admin, $this->company, $this->type, 'same body', 'one');
        $racer = new AuthorTemplateVersion(staleActiveRepository($this->repo, null), app(AuditEnvelope::class));

        $again = $racer->handle($this->admin, $this->company, $this->type, 'same body', 'two');

        expect($again->id)->toBe($first->id)
            ->and(TemplateVersion::query()->count())->toBe(1)
            ->and(AuditLogEntry::query()->count())->toBe(1);
    });

    it('refuses, in the repository, to write a version whose body is the active one already', function () {
        $first = $this->repo->author($this->company->id, $this->type, 'same body', $this->admin->id);

        expect(fn () => $this->repo->author($this->company->id, $this->type, 'same body', $this->admin->id))
            ->toThrow(TemplateUnchangedException::class);

        expect(TemplateVersion::query()->count())->toBe(1)->and($this->repo->activeFor($this->company->id, $this->type)->id)->toBe($first->id);
    });

    it('does save text that matches an older version but not the active one, as a new version', function () {
        $this->author->handle($this->admin, $this->company, $this->type, 'a', 'one');
        $this->author->handle($this->admin, $this->company, $this->type, 'b', 'two');
        $third = $this->author->handle($this->admin, $this->company, $this->type, 'a', 'three');

        expect($third->version)->toBe(3);
    });

    it('keeps the body exactly as written, including BBCode, line breaks and unicode', function () {
        $body = "[CENTER][B]{{unit}}[/B][/CENTER]\r\n[TABLE]\n  [TR][TD]✓ ✗[/TD][/TR]\n[/TABLE]";

        $version = $this->author->handle($this->admin, $this->company, $this->type, $body, 'why');

        expect($this->repo->find($version->id)->body)->toBe($body);
    });
});

describe('activating', function () {
    it('moves the pointer back to an older version, audited with a reason, and writes no new version', function () {
        $first = $this->author->handle($this->admin, $this->company, $this->type, 'first body', 'one');
        $this->author->handle($this->admin, $this->company, $this->type, 'second body', 'two');

        $this->activate->handle($this->admin, $first, 'The second edit broke the table');

        $entry = AuditLogEntry::query()->latest('id')->first();

        expect($this->repo->activeFor($this->company->id, $this->type)->id)->toBe($first->id)
            ->and(TemplateVersion::query()->count())->toBe(2)
            ->and($entry->action)->toBe('template_version.activated')
            ->and($entry->reason)->toBe('The second edit broke the table')
            ->and((int) $entry->target_id)->toBe($first->id);
    });

    it('requires a reason, and a rejected activation moves nothing', function () {
        $first = $this->author->handle($this->admin, $this->company, $this->type, 'first body', 'one');
        $second = $this->author->handle($this->admin, $this->company, $this->type, 'second body', 'two');

        expect(fn () => $this->activate->handle($this->admin, $first, null))->toThrow(ReasonRequiredException::class);

        expect($this->repo->activeFor($this->company->id, $this->type)->id)->toBe($second->id)
            ->and(AuditLogEntry::query()->count())->toBe(2);
    });

    it('stops a double-click that raced past the pre-check from recording a second activation', function () {
        $first = $this->author->handle($this->admin, $this->company, $this->type, 'first body', 'one');
        $this->author->handle($this->admin, $this->company, $this->type, 'second body', 'two');
        $this->activate->handle($this->admin, $first, 'roll back');
        $racer = new ActivateTemplateVersion(staleActiveRepository($this->repo, $this->repo->find($first->id + 1)), app(AuditEnvelope::class));

        $racer->handle($this->admin, $first, 'roll back again');

        expect(AuditLogEntry::query()->where('action', 'template_version.activated')->count())->toBe(1)
            ->and($this->repo->activeFor($this->company->id, $this->type)->id)->toBe($first->id);
    });

    it('treats activating the version that is already active as a no-op that is not logged', function () {
        $first = $this->author->handle($this->admin, $this->company, $this->type, 'first body', 'one');

        $this->activate->handle($this->admin, $first, 'again');

        expect(AuditLogEntry::query()->count())->toBe(1);
    });
});

it('lets two companies hold different active versions without cross-bleed', function () {
    $other = Unit::factory()->create(['type' => UnitType::Company]);

    $this->author->handle($this->admin, $this->company, $this->type, 'alpha standard', 'why');
    $this->author->handle($this->admin, $other, $this->type, 'bravo standard', 'why');

    expect($this->repo->activeFor($this->company->id, $this->type)->body)->toBe('alpha standard')
        ->and($this->repo->activeFor($other->id, $this->type)->body)->toBe('bravo standard');
});
