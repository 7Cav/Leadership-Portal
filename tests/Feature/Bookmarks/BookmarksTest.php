<?php

use App\Models\Bookmark;
use App\Models\PortalRoleGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../StaffPractices/fixture.php';

// The sidebar's bookmarks: a person keeps up to ten pages they may open, each a route on the allowlist with its
// parameters. A page they can no longer open is dropped the next time their bookmarks are read. Bookmarks belong to
// the person signed in as themselves, so view as and act as hide them and refuse every write.

beforeEach(function () {
    $this->tree = staffPracticeTree();
    $this->u = $this->tree['units'];
    $this->users = $this->tree['users'];
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function bookmarkRow(User $user, string $route, array $params, int $position = 1): Bookmark
{
    return Bookmark::query()->create([
        'user_id' => $user->id,
        'route' => $route,
        'params' => Bookmark::encodeParams($params),
        'label' => 'Stored label',
        'position' => $position,
    ]);
}

it('bookmarks a unit roster the user may open and lists it in the sidebar', function () {
    $pl = $this->users['pl'];

    $this->actingAs($pl)->from('/roster/'.$this->u['host']->id)
        ->post('/bookmarks', ['route' => 'roster.index', 'params' => ['unit' => $this->u['host']->id]])
        ->assertRedirect('/roster/'.$this->u['host']->id)
        ->assertSessionHasNoErrors();

    $row = Bookmark::query()->sole();
    expect($row->user_id)->toBe($pl->id)
        ->and($row->route)->toBe('roster.index')
        ->and($row->parameters())->toBe(['unit' => $this->u['host']->id])
        ->and($row->label)->toBe('Roster 1/1/A/1-7');

    $this->actingAs($pl)->get('/home')->assertInertia(fn (Assert $page) => $page
        ->where('bookmarks.limit', 10)
        ->has('bookmarks.items', 1)
        ->where('bookmarks.items.0.label', 'Roster 1/1/A/1-7')
        ->where('bookmarks.items.0.href', '/roster/'.$this->u['host']->id)
        ->where('bookmarks.current', null));
});

it('labels each kind of page and keeps a unit report on the unit, not the week', function () {
    $pl = $this->users['pl'];
    $rifleman = $this->tree['troopers']['rifleman'];

    $this->actingAs($pl)->post('/bookmarks', ['route' => 'reports.index', 'params' => ['unit' => $this->u['platoon']->id]])->assertSessionHasNoErrors();
    $this->actingAs($pl)->post('/bookmarks', ['route' => 'rollcall.show', 'params' => ['unit' => (string) $this->u['host']->id]])->assertSessionHasNoErrors();
    $this->actingAs($pl)->post('/bookmarks', ['route' => 'troopers.show', 'params' => ['trooper' => $rifleman->id]])->assertSessionHasNoErrors();
    $this->actingAs($pl)->post('/bookmarks', ['route' => 'settings.templates', 'params' => []])->assertSessionHasNoErrors();

    $this->actingAs($pl)->get('/home')->assertInertia(fn (Assert $page) => $page
        ->where('bookmarks.items.0.label', 'Report 1/A/1-7')
        ->where('bookmarks.items.0.href', '/reports/'.$this->u['platoon']->id)
        ->where('bookmarks.items.1.label', 'Roll call 1/1/A/1-7')
        ->where('bookmarks.items.2.label', $rifleman->display_name)
        ->where('bookmarks.items.2.href', '/troopers/'.$rifleman->id)
        ->where('bookmarks.items.3.label', 'Templates'));
});

it('offers the page on screen as the bookmark target, and says when it already is one', function () {
    $pl = $this->users['pl'];
    $host = $this->u['host'];

    $this->actingAs($pl)->get("/roster/{$host->id}?view=matrix")->assertInertia(fn (Assert $page) => $page
        ->where('bookmarks.current.route', 'roster.index')
        ->where('bookmarks.current.params', ['unit' => $host->id])
        ->where('bookmarks.current.label', 'Roster 1/1/A/1-7')
        ->where('bookmarks.current.bookmarkId', null));

    $row = bookmarkRow($pl, 'roster.index', ['unit' => $host->id]);
    $this->actingAs($pl)->get("/roster/{$host->id}")->assertInertia(fn (Assert $page) => $page
        ->where('bookmarks.current.bookmarkId', $row->id));

    // `/roster` names no unit: the one it lands on is the target.
    $this->actingAs($pl)->get('/roster')->assertInertia(fn (Assert $page) => $page
        ->where('bookmarks.current.route', 'roster.index')
        ->where('bookmarks.current.params', ['unit' => $this->u['platoon']->id]));

    // A week's report offers the unit's report, which always opens the current week.
    $this->actingAs($pl)->get("/reports/{$this->u['platoon']->id}/".staffPracticeWeek()->toDateString())->assertInertia(fn (Assert $page) => $page
        ->where('bookmarks.current.route', 'reports.index')
        ->where('bookmarks.current.params', ['unit' => $this->u['platoon']->id]));

    // Home is not on the allowlist.
    $this->actingAs($pl)->get('/home')->assertInertia(fn (Assert $page) => $page->where('bookmarks.current', null));
});

it('refuses a page the user cannot open, and one not on the allowlist', function () {
    $sl = $this->users['sl'];

    // The sibling section is outside a section leader's scope.
    $this->actingAs($sl)->post('/bookmarks', ['route' => 'roster.index', 'params' => ['unit' => $this->u['other']->id]])->assertForbidden();
    $this->actingAs($sl)->post('/bookmarks', ['route' => 'roster.index', 'params' => ['unit' => 999999]])->assertForbidden();
    // A section has a roll call; a platoon does not.
    $this->actingAs($this->users['pl'])->post('/bookmarks', ['route' => 'rollcall.show', 'params' => ['unit' => $this->u['platoon']->id]])->assertForbidden();
    // Admin only.
    $this->actingAs($sl)->post('/bookmarks', ['route' => 'settings.audit-log', 'params' => []])->assertForbidden();

    $this->actingAs($sl)->post('/bookmarks', ['route' => 'home', 'params' => []])->assertSessionHasErrors('bookmark');
    $this->actingAs($sl)->post('/bookmarks', ['route' => 'roster.index', 'params' => ['unit' => $this->u['host']->id, 'view' => 'matrix']])->assertSessionHasErrors('bookmark');
    $this->actingAs($sl)->post('/bookmarks', ['route' => 'roster.index', 'params' => ['unit' => '-1']])->assertSessionHasErrors('bookmark');

    expect(Bookmark::query()->count())->toBe(0);
});

it('keeps one bookmark per page', function () {
    $pl = $this->users['pl'];
    $body = ['route' => 'roster.index', 'params' => ['unit' => $this->u['host']->id]];

    $this->actingAs($pl)->post('/bookmarks', $body)->assertSessionHasNoErrors();
    $this->actingAs($pl)->post('/bookmarks', ['route' => 'roster.index', 'params' => ['unit' => (string) $this->u['host']->id]])->assertSessionHasNoErrors();

    expect(Bookmark::query()->count())->toBe(1);
});

it('holds at most ten bookmarks', function () {
    $admin = $this->users['admin'];
    $pages = ['settings.org-structure', 'settings.staff-grants', 'settings.templates', 'settings.games', 'settings.servers', 'settings.audit-log', 'settings.activity-log'];
    foreach ($pages as $index => $route) {
        bookmarkRow($admin, $route, [], $index + 1);
    }
    foreach (['host', 'other', 'platoon'] as $index => $unit) {
        bookmarkRow($admin, 'roster.index', ['unit' => $this->u[$unit]->id], 8 + $index);
    }

    $this->actingAs($admin)->post('/bookmarks', ['route' => 'roster.index', 'params' => ['unit' => $this->u['company']->id]])
        ->assertSessionHasErrors(['bookmark' => 'All 10 bookmark slots are in use. Remove one to add this page.']);

    expect(Bookmark::query()->where('user_id', $admin->id)->count())->toBe(10);
});

it('drops a bookmark the user can no longer open when the list is read', function () {
    $grant = $this->users['grant'];
    $company = $this->u['company'];

    $this->actingAs($grant)->post('/bookmarks', ['route' => 'roster.index', 'params' => ['unit' => $company->id]])->assertSessionHasNoErrors();
    // A unit since deleted, and a trooper page out of scope.
    bookmarkRow($grant, 'roster.index', ['unit' => 999999], 2);
    $kept = bookmarkRow($grant, 'settings.games', [], 3);

    $this->actingAs($grant)->get('/home')->assertInertia(fn (Assert $page) => $page
        ->has('bookmarks.items', 2)
        ->where('bookmarks.items.0.label', 'Roster A/1-7')
        ->where('bookmarks.items.1.label', 'Games'));
    expect(Bookmark::query()->where('user_id', $grant->id)->count())->toBe(2);

    // The grant ends: the company is no longer in scope.
    PortalRoleGrant::query()->where('user_id', $grant->id)->delete();

    $this->actingAs($grant)->get('/home')->assertInertia(fn (Assert $page) => $page
        ->has('bookmarks.items', 1)
        ->where('bookmarks.items.0.id', $kept->id));
    expect(Bookmark::query()->where('user_id', $grant->id)->pluck('id')->all())->toBe([$kept->id]);
});

it('bookmarks a guide by its title, and drops it while the docs are off', function () {
    config(['portal.docs' => true]);
    $sl = $this->users['sl'];

    $this->actingAs($sl)->get('/docs/basics/bookmarks')->assertInertia(fn (Assert $page) => $page
        ->where('bookmarks.current.route', 'docs.show')
        ->where('bookmarks.current.params', ['section' => 'basics', 'slug' => 'bookmarks']));
    $this->actingAs($sl)->post('/bookmarks', ['route' => 'docs.show', 'params' => ['slug' => 'bookmarks', 'section' => 'basics']])->assertSessionHasNoErrors();
    // A guide that does not exist cannot be bookmarked.
    $this->actingAs($sl)->post('/bookmarks', ['route' => 'docs.show', 'params' => ['section' => 'basics', 'slug' => 'nope']])->assertForbidden();

    $this->actingAs($sl)->get('/home')->assertInertia(fn (Assert $page) => $page
        ->where('bookmarks.items.0.label', 'Bookmark the pages you use most')
        ->where('bookmarks.items.0.href', '/docs/basics/bookmarks'));

    config(['portal.docs' => false]);
    $this->actingAs($sl)->get('/home')->assertInertia(fn (Assert $page) => $page->has('bookmarks.items', 0));
    expect(Bookmark::query()->count())->toBe(0);
});

it('removes and reorders only the user\'s own bookmarks', function () {
    $pl = $this->users['pl'];
    $first = bookmarkRow($pl, 'settings.games', [], 1);
    $second = bookmarkRow($pl, 'settings.templates', [], 2);
    $theirs = bookmarkRow($this->users['sl'], 'settings.games', []);

    $this->actingAs($pl)->patch("/bookmarks/{$second->id}", ['direction' => 'up'])->assertRedirect();
    $this->actingAs($pl)->get('/home')->assertInertia(fn (Assert $page) => $page
        ->where('bookmarks.items.0.id', $second->id)
        ->where('bookmarks.items.1.id', $first->id));

    $this->actingAs($pl)->patch("/bookmarks/{$theirs->id}", ['direction' => 'up'])->assertNotFound();
    $this->actingAs($pl)->delete("/bookmarks/{$theirs->id}")->assertNotFound();
    $this->actingAs($pl)->delete("/bookmarks/{$first->id}")->assertRedirect();

    expect(Bookmark::query()->pluck('id')->sort()->values()->all())->toBe([$second->id, $theirs->id]);
});

it('hides bookmarks and refuses their writes during view as', function () {
    $admin = $this->users['admin'];
    $row = bookmarkRow($admin, 'settings.audit-log', []);

    $this->actingAs($admin)->post('/view-as', ['kind' => 'unit', 'id' => $this->u['host']->id]);

    // The previewed leader may not open the audit log, but the Admin's bookmark is not theirs to drop.
    $this->actingAs($admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('bookmarks', null));
    $this->actingAs($admin)->post('/bookmarks', ['route' => 'settings.games', 'params' => []])->assertForbidden();
    $this->actingAs($admin)->delete("/bookmarks/{$row->id}")->assertForbidden();

    expect(Bookmark::query()->pluck('id')->all())->toBe([$row->id]);
});

it('hides bookmarks and refuses their writes while an Admin acts as someone', function () {
    $admin = $this->users['admin'];
    $row = bookmarkRow($admin, 'settings.audit-log', []);

    $this->actingAs($admin)->post('/act-as', ['kind' => 'unit', 'id' => $this->u['host']->id])->assertRedirect('/home');

    $this->actingAs($admin)->get('/home')->assertInertia(fn (Assert $page) => $page->where('bookmarks', null));
    $this->actingAs($admin)->post('/bookmarks', ['route' => 'settings.games', 'params' => []])->assertForbidden();
    $this->actingAs($admin)->patch("/bookmarks/{$row->id}", ['direction' => 'down'])->assertForbidden();

    expect(Bookmark::query()->pluck('id')->all())->toBe([$row->id]);
});

it('keeps bookmarks to signed-in users', function () {
    $this->post('/bookmarks', ['route' => 'settings.games', 'params' => []])->assertRedirect('/login');
});
