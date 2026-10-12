<?php

namespace App\Http\Controllers;

use App\Authorization\ScopeResolver;
use App\Commands\GameIdentities\GameIdentityForbiddenException;
use App\Commands\GameIdentities\RemoveTrooperGameIdentity;
use App\Commands\GameIdentities\RemoveTrooperGameIdentityHandler;
use App\Commands\GameIdentities\SetTrooperGameIdentity;
use App\Commands\GameIdentities\SetTrooperGameIdentityHandler;
use App\Models\GamePlatform;
use App\Models\Trooper;
use App\Models\TrooperGameIdentity;
use App\Models\Unit;
use App\Repositories\GameIdentityLinkException;
use App\Repositories\TrooperGameIdentityRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The link-identity screen (Story 7.2, binding design: mockups/v2/link-identity.html variant C,
 * "Needs-a-link queue"): a leader links the troopers in their scope to their in-game identities,
 * one per platform (AD-19), and edits or removes those links.
 *
 * Reads go straight to the repositories; every write is an audited Command, scope-gated by the
 * ScopeResolver inside its handler (AD-8) — a trooper outside the viewer's scope is a 403 whatever
 * the page offered. A platform is rendered for every {@see GamePlatform} case, so a new game needs
 * no change here.
 */
class GameIdentityController extends Controller
{
    public function __construct(
        private readonly ScopeResolver $scope,
        private readonly UnitRepository $units,
        private readonly TrooperRepository $troopers,
        private readonly TrooperGameIdentityRepository $identities,
    ) {}

    public function index(Request $request): Response
    {
        $scope = $this->scope->resolve($request->user());
        $sections = $this->units->sectionsAmong($scope)->keyBy('id');
        $troopers = $this->troopers->placedInAny($sections->keys());
        $identities = $this->identities->forTroopers($troopers->modelKeys())->groupBy('trooper_id');

        return Inertia::render('GameIdentities', [
            'platforms' => array_map(fn (GamePlatform $platform) => [
                'value' => $platform->value,
                'label' => $platform->label(),
                'idLabel' => $platform->idLabel(),
            ], GamePlatform::cases()),
            'troopers' => $troopers->map(fn (Trooper $trooper) => [
                'id' => $trooper->id,
                'displayName' => $trooper->display_name,
                'billet' => $trooper->billet,
                'section' => $this->designation($sections->get($trooper->primary_section_id)),
                'identities' => ($identities->get($trooper->id) ?? collect())
                    ->mapWithKeys(fn (TrooperGameIdentity $identity) => [$identity->platform->value => [
                        'playerName' => $identity->player_name,
                        'platformId' => $identity->platform_id,
                    ]])->all(),
            ])->sortBy(fn (array $row) => mb_strtolower($row['displayName']))->values()->all(),
        ]);
    }

    /**
     * Link the trooper on `$platform`, or correct the identity they already hold there. An
     * identifier another trooper already owns comes back as a validation error on `platform_id`.
     */
    public function update(Request $request, int $trooper, GamePlatform $platform, SetTrooperGameIdentityHandler $handler): RedirectResponse
    {
        $data = $request->validate([
            'player_name' => ['required', 'string', 'max:255'],
            'platform_id' => ['required', 'string', 'max:255'],
        ]);

        try {
            $handler->handle($request->user(), new SetTrooperGameIdentity($trooper, $platform, $data['player_name'], $data['platform_id']));
        } catch (GameIdentityForbiddenException $e) {
            abort(403, $e->getMessage());
        } catch (GameIdentityLinkException) {
            throw ValidationException::withMessages(['platform_id' => $this->collisionMessage($platform, $data['platform_id'])]);
        }

        return back();
    }

    public function destroy(Request $request, int $trooper, GamePlatform $platform, RemoveTrooperGameIdentityHandler $handler): RedirectResponse
    {
        try {
            $handler->handle($request->user(), new RemoveTrooperGameIdentity($trooper, $platform));
        } catch (GameIdentityForbiddenException $e) {
            abort(403, $e->getMessage());
        }

        return back();
    }

    /**
     * "Already linked to SGT Ortega.M on Hell Let Loose." — the owner is named so the leader knows
     * whose row to fix. Roster names are not secret within the portal, so an owner outside the
     * leader's scope is still named: the alternative is an error nobody can act on.
     */
    private function collisionMessage(GamePlatform $platform, string $platformId): string
    {
        $owner = $this->identities->findByPlatformId($platform, trim($platformId));
        $name = $owner === null ? null : $this->troopers->find($owner->trooper_id)?->display_name;

        return 'Already linked to '.($name ?? 'another trooper')." on {$platform->label()}.";
    }

    private function designation(?Unit $section): ?string
    {
        return $section === null ? null : ($section->callsign ?? $section->name);
    }
}
