<?php

namespace App\Authorization;

use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Illuminate\Support\Collection;

/**
 * Who may open each page a person can bookmark: one rule per page, which the page's controller enforces
 * ({@see PageDecision::orAbort()}) and the sidebar's bookmarks read ({@see PageDecision::allowed()}), so the two
 * cannot drift. Each answers with the status the page has always refused with. Every rule reads the ScopeResolver
 * (AD-8) or StaffReportAccess; none decides anything of its own. The docs pages' rule is the Docs layer's
 * (`DocsPages::enabled()` and `DocsPages::readable()`).
 *
 * `$scope` is {@see ScopeResolver::resolve()} for the viewer when the caller already holds it.
 */
final class PageAccess
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly StaffReportAccess $staffReports,
        private readonly UnitRepository $units,
    ) {}

    /**
     * A unit's roster (`/roster/{unit}`): any unit in scope. Out of scope and missing are the same 403, so ids cannot
     * be probed.
     *
     * @param  ?Collection<int, int>  $scope
     * @return PageDecision<Unit>
     */
    public function roster(User $user, int $unitId, ?Collection $scope = null): PageDecision
    {
        return $this->unitInScope($user, $unitId, $scope) ?? PageDecision::deny(403, 'That unit is outside your scope.');
    }

    /**
     * A unit's training-readiness matrix (`/readiness/{unit}`): any unit in scope, as the roster.
     *
     * @param  ?Collection<int, int>  $scope
     * @return PageDecision<Unit>
     */
    public function readiness(User $user, int $unitId, ?Collection $scope = null): PageDecision
    {
        return $this->roster($user, $unitId, $scope);
    }

    /**
     * A unit's reports (`/reports/{unit}`): a line unit in scope. A missing unit is a 404, one out of scope a 403, a
     * group off the line a 404 (it has no weekly report).
     *
     * @param  ?Collection<int, int>  $scope
     * @return PageDecision<Unit>
     */
    public function reports(User $user, int $unitId, ?Collection $scope = null): PageDecision
    {
        $unit = $this->units->find($unitId);

        return match (true) {
            $unit === null => PageDecision::deny(404),
            ! ($scope ?? $this->scope->resolve($user))->contains($unit->id) => PageDecision::deny(403),
            ! $unit->isLine() => PageDecision::deny(404),
            default => PageDecision::allow($unit),
        };
    }

    /**
     * A section's roll call (`/rollcall/{unit}`): scope first, so a unit outside it is a 403 whether or not it exists
     * (AD-8); then a 404 for anything but a section.
     *
     * @param  ?Collection<int, int>  $scope
     * @return PageDecision<Unit>
     */
    public function rollcall(User $user, int $unitId, ?Collection $scope = null): PageDecision
    {
        if (! ($scope ?? $this->scope->resolve($user))->contains($unitId)) {
            return PageDecision::deny(403, 'This section is outside your scope.');
        }

        $section = $this->units->find($unitId);

        return $section?->type === UnitType::Section ? PageDecision::allow($section) : PageDecision::deny(404);
    }

    /**
     * A trooper's milpac (`/troopers/{trooper}`): one the viewer may see ({@see ScopeResolver::canSee()}), or their own
     * ({@see ScopeResolver::isOwnTrooper()}). Anything else is a 404, like a trooper who does not exist. The subject is
     * whether it opens only as their own page.
     *
     * @param  ?Collection<int, int>  $scope
     * @return PageDecision<bool>
     */
    public function milpac(User $user, Trooper $trooper, ?Collection $scope = null): PageDecision
    {
        if ($this->scope->canSee($user, $trooper, $scope)) {
            return PageDecision::allow(false);
        }

        return $this->scope->isOwnTrooper($user, $trooper) ? PageDecision::allow(true) : PageDecision::deny(404);
    }

    /**
     * The staff reports overview (`/staff-reports`): for whoever reads some HQ's staff reports, else a 404. The subject
     * is those HQs ({@see StaffReportAccess::readableHqs()}).
     *
     * @return PageDecision<Collection<int, Unit>>
     */
    public function staffReports(User $user): PageDecision
    {
        $hqs = $this->staffReports->readableHqs($user);

        return $hqs->isEmpty() ? PageDecision::deny(404) : PageDecision::allow($hqs);
    }

    /**
     * A Settings page open to everyone signed in, which shows a non-Admin only what is theirs (org structure, staff
     * grants, templates, games).
     *
     * @return PageDecision<bool>
     */
    public function openSetting(User $user): PageDecision
    {
        return PageDecision::allow();
    }

    /**
     * An Admin-only Settings page (servers, audit log, activity log): {@see ScopeResolver::actsAsAdmin()}, so an Admin
     * previewing a leader sees what that leader would.
     *
     * @return PageDecision<bool>
     */
    public function adminSetting(User $user, string $message = 'Only an Admin may open this page.'): PageDecision
    {
        return $this->scope->actsAsAdmin($user) ? PageDecision::allow() : PageDecision::deny(403, $message);
    }

    /**
     * Settings > Demo data: a 404 unless the demo tools are on (DEMO_TOOLS), then Admin only.
     *
     * @return PageDecision<bool>
     */
    public function demoData(User $user): PageDecision
    {
        return (bool) config('portal.demo_tools')
            ? $this->adminSetting($user, 'Only an Admin may manage demo data.')
            : PageDecision::deny(404);
    }

    /**
     * @param  ?Collection<int, int>  $scope
     * @return ?PageDecision<Unit>
     */
    private function unitInScope(User $user, int $unitId, ?Collection $scope): ?PageDecision
    {
        $unit = ($scope ?? $this->scope->resolve($user))->contains($unitId) ? $this->units->find($unitId) : null;

        return $unit === null ? null : PageDecision::allow($unit);
    }
}
