<?php

use App\Models\User;
use App\Repositories\IdentityLinkException;
use App\Repositories\UserRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// The identity-link write is the one DB mutation on the login path, and its failure mode matters
// more than its happy path: it runs *after* Auth::login(), so anything escaping this layer 500s a
// request whose user is already authenticated.

it('translates a query failure into an IdentityLinkException, keeping the cause', function () {
    $user = User::create(['forum_user_id' => 4, 'username' => 'Hale.S']);
    $cause = new QueryException('mysql', 'update users set cav_id = ?', [77], new RuntimeException('duplicate key'));

    DB::shouldReceive('transaction')->once()->andThrow($cause);

    try {
        app(UserRepository::class)->linkCavId($user, 77);
        $this->fail('Expected an IdentityLinkException.');
    } catch (IdentityLinkException $e) {
        expect($e->getMessage())->toContain('77')
            ->and($e->getMessage())->toContain((string) $user->id)
            ->and($e->getPrevious())->toBe($cause);
    }
});

it('translates a raw PDOException too, which DB::transaction does not wrap', function () {
    // `ManagesTransactions::commit()` calls `$pdo->commit()` unwrapped and
    // `handleCommitTransactionException()` rethrows as-is, so a connection lost at COMMIT arrives
    // as a bare PDOException. `QueryException extends PDOException`, not the reverse, so catching
    // only the narrower type would let this escape and 500 an authenticated request.
    $user = User::create(['forum_user_id' => 4, 'username' => 'Hale.S']);

    DB::shouldReceive('transaction')->once()->andThrow(new PDOException('MySQL server has gone away'));

    expect(fn () => app(UserRepository::class)->linkCavId($user, 77))
        ->toThrow(IdentityLinkException::class);
});

it('clears a link the portal has proven wrong', function () {
    $user = User::create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);

    app(UserRepository::class)->unlinkCavId($user);

    expect($user->fresh()->cav_id)->toBeNull();
});

it('displaces the previous holder of a reassigned milpac id, and only that row', function () {
    // `cav_id` is unique, so without the pre-clear this write throws a constraint violation after
    // Auth::login() has already run. The bystander proves the clear is targeted, not a blanket one.
    $previous = User::create(['forum_user_id' => 555, 'cav_id' => 77, 'username' => 'Previous.H']);
    $bystander = User::create(['forum_user_id' => 556, 'cav_id' => 78, 'username' => 'Bystander.K']);
    $user = User::create(['forum_user_id' => 4, 'username' => 'Hale.S']);

    app(UserRepository::class)->linkCavId($user, 77);

    expect($user->fresh()->cav_id)->toBe(77)
        ->and($previous->fresh()->cav_id)->toBeNull()
        ->and($bystander->fresh()->cav_id)->toBe(78);
});

it('is idempotent when the trooper already holds that milpac id', function () {
    $user = User::create(['forum_user_id' => 4, 'cav_id' => 77, 'username' => 'Hale.S']);

    app(UserRepository::class)->linkCavId($user, 77);

    // The row must not clear its own link on the way to re-claiming it.
    expect($user->fresh()->cav_id)->toBe(77);
});

it('leaves cav_id alone when a forum re-login updates the identity row', function () {
    // Regression guard from Story 1.4: roster enrichment owns cav_id, the forum upsert must not.
    $repository = app(UserRepository::class);
    $repository->upsertFromForumIdentity(4, 'Hale.S', 'hale@7cav.us');
    $repository->linkCavId(User::firstWhere('forum_user_id', 4), 77);

    $repository->upsertFromForumIdentity(4, 'Hale.Sam', 'hale@7cav.us');

    expect(User::firstWhere('forum_user_id', 4))
        ->username->toBe('Hale.Sam')
        ->cav_id->toBe(77);
});
