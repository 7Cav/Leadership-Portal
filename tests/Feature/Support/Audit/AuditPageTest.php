<?php

use App\Http\Controllers\Settings\AuditLogController;
use App\Models\AuditLogEntry;
use App\Models\User;
use App\Support\Audit\AuditPage;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

// The page a change was made on, kept with its audit entry so the log can link back to it.

function auditPageRequest(?string $referer, string $url = 'http://localhost/settings/games/1/retire'): Request
{
    $request = Request::create($url, 'POST');
    if ($referer !== null) {
        $request->headers->set('referer', $referer);
    }

    return $request;
}

it('keeps the path and query of a page on this portal', function () {
    expect(AuditPage::of(auditPageRequest('http://localhost/rollcall/12?week=2026-11-14&step=2')))->toBe('/rollcall/12?week=2026-11-14&step=2')
        ->and(AuditPage::of(auditPageRequest('http://LOCALHOST/settings/games')))->toBe('/settings/games');
});

it('keeps nothing for another site, a malformed address or no page at all', function (?string $referer) {
    expect(AuditPage::of(auditPageRequest($referer)))->toBeNull();
})->with([
    'another site' => ['https://example.com/settings/games'],
    'no host' => ['/settings/games'],
    'not an address' => ['not a url'],
    'none' => [null],
    // This portal's host, but a path a browser reads as another site (15NOV26).
    'a second slash' => ['http://localhost//evil.example/login'],
    'a backslash' => ['http://localhost/\\evil.example/login'],
]);

it('cuts a very long address to what the column holds', function () {
    expect(mb_strlen(AuditPage::of(auditPageRequest('http://localhost/rollcall/1?'.str_repeat('a', 900)))))->toBe(AuditPage::MAX);
});

it('records the page with the entry, and the audit log links to it', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->withHeader('referer', 'http://localhost/settings/games')
        ->post('/settings/games/'.game('hll')->id.'/retire')->assertSessionHasNoErrors();

    $entry = AuditLogEntry::query()->sole();
    expect($entry->page)->toBe('/settings/games');

    $this->actingAs($admin)->get('/settings/audit-log')->assertInertia(fn (Assert $page) => $page
        ->where('entries.data.0.page', ['href' => '/settings/games', 'label' => 'Settings, Games']));
});

it('records no page for a change with no request page behind it', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/settings/games/'.game('hll')->id.'/retire')->assertSessionHasNoErrors();

    expect(AuditLogEntry::query()->sole()->page)->toBeNull();
});

it('names the page by the first part of its address', function (string $page, string $label) {
    expect(AuditLogController::pageLink($page))->toBe(['href' => $page, 'label' => $label]);
})->with([
    ['/rollcall/12?week=2026-11-14', 'Roll call'],
    ['/reports/5/2026-11-07', 'Reports'],
    ['/settings/org-structure', 'Settings, Org structure'],
    ['/troopers/44', 'Trooper page'],
    ['/game-identities', 'Game identities'],
    ['/', 'Home'],
    ['/something-new', 'Something new'],
]);

it('links nowhere for an entry with no page', function () {
    expect(AuditLogController::pageLink(null))->toBeNull()
        ->and(AuditLogController::pageLink('https://example.com/x'))->toBeNull();
});

it('never links to another site from an entry stored with such a page', function (string $page) {
    expect(AuditLogController::pageLink($page))->toBeNull();
})->with([
    'a second slash' => ['//evil.example/login'],
    'a backslash' => ['/\\evil.example/login'],
    'a control character' => ["/\t/evil.example"],
]);
