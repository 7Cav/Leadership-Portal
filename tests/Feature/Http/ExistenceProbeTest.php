<?php

use App\Models\PortalRoleGrant;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\Unit;
use App\Models\User;

/**
 * A gated route answers a missing id and a forbidden one alike, so a user who may not act on a record
 * cannot learn whether its id exists. Ids are looked up after the gate rather than by route model
 * binding, which runs first and answers 404 for a missing id before any gate could say 403.
 */
const PROBE_MISSING = 999999;

describe('unit screens gated by scope', function () {
    it('answers a unit outside scope and a missing one with the same 403', function (string $path) {
        $outside = Unit::factory()->create();
        $user = User::factory()->serving()->create();

        $this->actingAs($user)->get(sprintf($path, $outside->id))->assertForbidden();
        $this->actingAs($user)->get(sprintf($path, PROBE_MISSING))->assertForbidden();
    })->with([
        'roster' => ['/roster/%d'],
        'readiness matrix' => ['/readiness/%d'],
    ]);
});

describe('Admin-only writes', function () {
    it('answers a non-Admin the same 403 for an id that exists and one that does not', function (string $method, string $path, Closure $existing, array $body) {
        $user = User::factory()->serving()->create();

        $this->actingAs($user)->call($method, sprintf($path, $existing()), $body)->assertForbidden();
        $this->actingAs($user)->call($method, sprintf($path, PROBE_MISSING), $body)->assertForbidden();
    })->with(probeRoutes());

    it('still tells an Admin that an id is gone', function (string $method, string $path, Closure $existing, array $body) {
        $this->actingAs(User::factory()->admin()->create())
            ->call($method, sprintf($path, PROBE_MISSING), $body)
            ->assertNotFound();
    })->with(probeRoutes());

    it('refuses a non-Admin demo-data request before validating it, so a missing unit is no 422', function (string $method) {
        config()->set('portal.demo_tools', true);
        $user = User::factory()->serving()->create();
        $unit = Unit::factory()->create();

        $this->actingAs($user)->call($method, '/settings/demo-data', ['unit_id' => $unit->id, 'weeks' => 1])->assertForbidden();
        $this->actingAs($user)->call($method, '/settings/demo-data', ['unit_id' => PROBE_MISSING, 'weeks' => 1])->assertForbidden();
    })->with(['generate' => ['POST'], 'purge' => ['DELETE']]);
});

/** @return array<string, array{string, string, Closure, array<string, mixed>}> */
function probeRoutes(): array
{
    return [
        'rename a unit' => ['PATCH', '/settings/org-structure/%d', fn () => Unit::factory()->create()->id, ['name' => 'Renamed']],
        'delete a unit' => ['DELETE', '/settings/org-structure/%d', fn () => Unit::factory()->create()->id, []],
        'revoke a grant' => ['DELETE', '/settings/staff-grants/%d', fn () => PortalRoleGrant::factory()->create()->id, ['reason' => 'why']],
        'activate a template version' => ['POST', '/settings/templates/%d/activate', fn () => TemplateVersion::factory()->create()->id, ['reason' => 'why']],
        'switch a template' => ['POST', '/settings/templates/%d/switch', fn () => Template::factory()->create()->id, ['enabled' => false]],
        'make a company default' => ['POST', '/settings/templates/%d/company-active', fn () => Template::factory()->create()->id, []],
    ];
}
