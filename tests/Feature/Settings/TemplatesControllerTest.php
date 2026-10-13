<?php

use App\Commands\Templates\AuthorTemplateVersion;
use App\Models\ArtifactType;
use App\Models\AuditLogEntry;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\TemplateVersionRepository;
use App\Rollcall\TemplateSyntax;

// Story 3.5: the Admin template screen. Viewing is open; every write is Admin-only on the server (AD-6, AD-16, AD-17).

beforeEach(function () {
    $this->company = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Echo Company', 'callsign' => 'E/2-7']);
    $this->admin = User::factory()->admin()->create();
    $this->repo = app(TemplateVersionRepository::class);
});

function authorTemplate($test, string $body, ?Unit $company = null): TemplateVersion
{
    return app(AuthorTemplateVersion::class)->handle($test->admin, $company ?? $test->company, ArtifactType::RollcallBbcode, $body, 'seed');
}

describe('viewing', function () {
    it('requires authentication', function () {
        $this->get('/settings/templates')->assertRedirect(route('login'));
    });

    it('shows any authenticated user the active template and its history, read-only', function () {
        authorTemplate($this, 'first body');
        authorTemplate($this, 'second body');

        $this->actingAs(User::factory()->create())->get('/settings/templates')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Settings/Templates')
            ->where('isAdmin', false)
            ->where('company', $this->company->id)
            ->where('active.body', 'second body')
            ->where('active.version', 2)
            ->where('artifactType', ['value' => 'rollcall_bbcode', 'label' => 'Rollcall BBCode'])
            ->has('history', 2)
            ->where('history.0.isActive', true)
            ->where('history.1.isActive', false));
    });

    it('sends the placeholders an Admin may write and a starter template', function () {
        $this->actingAs($this->admin)->get('/settings/templates')->assertInertia(fn ($page) => $page
            ->where('syntax.values', TemplateSyntax::TOP_STRINGS)
            ->where('syntax.rowFlags', TemplateSyntax::ROW_FLAGS)
            ->where('syntax.filters', ['upper', 'lower'])
            ->where('starter', TemplateSyntax::STARTER));
    });

    it('lists the history newest first, with who saved each version', function () {
        authorTemplate($this, 'first body');
        authorTemplate($this, 'second body');

        $this->actingAs($this->admin)->get('/settings/templates')->assertInertia(fn ($page) => $page
            ->where('history.0.version', 2)
            ->where('history.1.version', 1)
            ->where('history.0.createdBy', $this->admin->username)
            ->where('isAdmin', true));
    });

    it('offers only company units, and opens the one asked for', function () {
        $other = Unit::factory()->create(['type' => UnitType::Company, 'name' => 'Foxtrot Company']);
        Unit::factory()->create(['type' => UnitType::Platoon]);
        authorTemplate($this, 'echo body');
        authorTemplate($this, 'foxtrot body', $other);

        $this->actingAs($this->admin)->get('/settings/templates?company='.$other->id)->assertInertia(fn ($page) => $page
            ->has('companies', 2)
            ->where('company', $other->id)
            ->where('active.body', 'foxtrot body')
            ->has('history', 1));
    });

    it('falls back to the first company for a company id that is not one', function () {
        $section = Unit::factory()->create(['type' => UnitType::Section]);

        $this->actingAs($this->admin)->get('/settings/templates?company='.$section->id)->assertInertia(fn ($page) => $page->where('company', $this->company->id));
    });

    it('says plainly when a company has no template yet', function () {
        $this->actingAs($this->admin)->get('/settings/templates')->assertInertia(fn ($page) => $page
            ->where('active', null)
            ->where('history', []));
    });

    it('renders with no companies at all', function () {
        $this->company->delete();

        $this->actingAs($this->admin)->get('/settings/templates')->assertOk()->assertInertia(fn ($page) => $page
            ->where('companies', [])
            ->where('company', null)
            ->where('active', null));
    });

    it('follows a view-as preview for the controls it shows, and the real flag for writes', function () {
        $leader = Unit::factory()->create(['type' => UnitType::Section]);
        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $leader->id])->assertSessionHasNoErrors();

        $this->get('/settings/templates')->assertInertia(fn ($page) => $page->where('isAdmin', false));
    });
});

describe('saving a new version', function () {
    it('lets an Admin save an edit, which becomes version 1 and the active one', function () {
        $this->actingAs($this->admin)->post('/settings/templates', [
            'company_id' => $this->company->id,
            'body' => '[CENTER]{{unit}}[/CENTER]',
            'reason' => 'First authoring',
        ])->assertRedirect(route('settings.templates', ['company' => $this->company->id]));

        $active = $this->repo->activeFor($this->company->id, ArtifactType::RollcallBbcode);

        expect($active->version)->toBe(1)
            ->and($active->body)->toBe('[CENTER]{{unit}}[/CENTER]')
            ->and(AuditLogEntry::query()->sole()->reason)->toBe('First authoring');
    });

    it('refuses a non-Admin on the server, whatever the page showed, and writes nothing', function () {
        $this->actingAs(User::factory()->create())->post('/settings/templates', [
            'company_id' => $this->company->id,
            'body' => 'body',
            'reason' => 'why',
        ])->assertForbidden();

        expect(TemplateVersion::query()->count())->toBe(0)
            ->and(AuditLogEntry::query()->count())->toBe(0);
    });

    it('refuses a signed-out request', function () {
        $this->post('/settings/templates', ['company_id' => $this->company->id, 'body' => 'b', 'reason' => 'r'])->assertRedirect(route('login'));
    });

    it('refuses even a view-as preview of a leader: the write reads the real Admin flag, and the previewed state is read-only', function () {
        $leader = Unit::factory()->create(['type' => UnitType::Section]);
        $this->actingAs($this->admin)->post('/view-as', ['kind' => 'unit', 'id' => $leader->id])->assertSessionHasNoErrors();

        $this->post('/settings/templates', ['company_id' => $this->company->id, 'body' => 'b', 'reason' => 'r']);

        expect(TemplateVersion::query()->count())->toBe(0);
    });

    it('validates the company, body and reason', function (array $payload, string $field) {
        $this->actingAs($this->admin)->post('/settings/templates', $payload + ['company_id' => $this->company->id, 'body' => 'body', 'reason' => 'why'])
            ->assertSessionHasErrors($field);

        expect(TemplateVersion::query()->count())->toBe(0);
    })->with([
        'no body' => [['body' => ''], 'body'],
        'no reason' => [['reason' => ''], 'reason'],
        'reason too long' => [['reason' => str_repeat('r', 1001)], 'reason'],
        'unknown company' => [['company_id' => 999999], 'company_id'],
        'body too long' => [['body' => str_repeat('b', AuthorTemplateVersion::MAX_BODY_LENGTH + 1)], 'body'],
    ]);

    it('refuses a unit that is not a company with a form error on the company', function () {
        $platoon = Unit::factory()->create(['type' => UnitType::Platoon]);

        $this->actingAs($this->admin)->post('/settings/templates', ['company_id' => $platoon->id, 'body' => 'b', 'reason' => 'r'])
            ->assertSessionHasErrors('company_id');
    });

    it('keeps the earlier version when an Admin saves a second edit', function () {
        $first = authorTemplate($this, 'first body');

        $this->actingAs($this->admin)->post('/settings/templates', ['company_id' => $this->company->id, 'body' => 'second body', 'reason' => 'tweak']);

        expect(TemplateVersion::query()->count())->toBe(2)
            ->and($this->repo->find($first->id)->body)->toBe('first body')
            ->and($this->repo->activeFor($this->company->id, ArtifactType::RollcallBbcode)->body)->toBe('second body');
    });
});

describe('making an older version active', function () {
    it('lets an Admin roll back, with a reason, and writes no new version', function () {
        $first = authorTemplate($this, 'first body');
        authorTemplate($this, 'second body');

        $this->actingAs($this->admin)->post("/settings/templates/{$first->id}/activate", ['reason' => 'The edit broke the table'])
            ->assertRedirect(route('settings.templates', ['company' => $this->company->id]));

        expect($this->repo->activeFor($this->company->id, ArtifactType::RollcallBbcode)->id)->toBe($first->id)
            ->and(TemplateVersion::query()->count())->toBe(2);
    });

    it('refuses a non-Admin on the server and moves nothing', function () {
        $first = authorTemplate($this, 'first body');
        $second = authorTemplate($this, 'second body');

        $this->actingAs(User::factory()->create())->post("/settings/templates/{$first->id}/activate", ['reason' => 'why'])->assertForbidden();

        expect($this->repo->activeFor($this->company->id, ArtifactType::RollcallBbcode)->id)->toBe($second->id);
    });

    it('requires a reason', function () {
        $first = authorTemplate($this, 'first body');
        authorTemplate($this, 'second body');

        $this->actingAs($this->admin)->post("/settings/templates/{$first->id}/activate", ['reason' => ''])->assertSessionHasErrors('reason');
    });

    it('answers a version that does not exist with 404', function () {
        $this->actingAs($this->admin)->post('/settings/templates/999999/activate', ['reason' => 'why'])->assertNotFound();
    });
});
