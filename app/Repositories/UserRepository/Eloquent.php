<?php

namespace App\Repositories\UserRepository;

use App\Models\User;
use App\Repositories\IdentityLinkException;
use App\Repositories\UserRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class Eloquent implements UserRepository
{
    public function upsertFromForumIdentity(int $forumUserId, string $username, ?string $email): User
    {
        return User::updateOrCreate(
            ['forum_user_id' => $forumUserId],
            ['username' => $username, 'email' => $email],
        );
    }

    /**
     * @throws IdentityLinkException when the link could not be written
     */
    public function linkCavId(User $user, int $cavId): User
    {
        try {
            $this->writeCavIdLink($user, $cavId);
        } catch (Throwable $e) {
            // `Throwable`, not `QueryException`. `DB::transaction()` does not wrap the BEGIN or
            // COMMIT it issues: `ManagesTransactions::commit()` calls `$pdo->commit()` directly
            // and `handleCommitTransactionException()` rethrows as-is, so a lost connection at
            // commit arrives as a raw `PDOException` — and `QueryException extends PDOException`,
            // not the reverse, so catching the narrower type misses it. It would then escape all
            // the way out and 500 a request where `Auth::login()` has already run. Translating
            // everything here is the repository honouring its own contract: nothing DB-shaped
            // leaves this layer.
            throw IdentityLinkException::cavIdNotLinked((int) $user->getKey(), $cavId, $e);
        }

        return $user;
    }

    public function unlinkCavId(User $user): User
    {
        try {
            $user->update(['cav_id' => null]);
        } catch (Throwable $e) {
            throw IdentityLinkException::cavIdNotUnlinked((int) $user->getKey(), $e);
        }

        return $user;
    }

    public function find(int $id): ?User
    {
        return User::find($id);
    }

    public function all(): Collection
    {
        return User::query()->orderBy('username')->get();
    }

    public function nonAdminUsers(): Collection
    {
        return User::query()->where('is_admin', false)->orderBy('username')->get();
    }

    public function firstAdmin(): ?User
    {
        return User::query()->where('is_admin', true)->orderBy('id')->first();
    }

    public function withUsername(string $username): Collection
    {
        return User::query()->where('username', $username)->orderBy('id')->get();
    }

    public function setAdmin(User $user, bool $isAdmin): User
    {
        $user->forceFill(['is_admin' => $isAdmin])->save();

        return $user;
    }

    /**
     * What the transaction does **not** do is make concurrent logins collision-free, and it is
     * worth being precise since a future reader will trust this comment. The `lockForUpdate()`
     * below takes a record lock only when a row actually holds the id, which correctly
     * serializes the *displacement* case. When no row holds it, InnoDB takes a gap lock instead,
     * and gap locks are mutually compatible — so two logins resolving the same new id can both
     * pass the SELECT and then deadlock on the insert-intention lock. Either way the failure
     * surfaces as a `QueryException` from inside the callback and is translated in
     * {@see linkCavId()}'s catch, so the login degrades rather than 500s; the race is narrowed
     * and made survivable, not eliminated.
     *
     * The unlink is logged because it *destroys* a portal-owned link on the strength of an
     * external payload. When Epic 2's sync or a later scope decision reads that null, this line
     * is the only thing that will explain it.
     */
    private function writeCavIdLink(User $user, int $cavId): void
    {
        DB::transaction(function () use ($user, $cavId) {
            $displaced = User::where('cav_id', $cavId)
                ->whereKeyNot($user->getKey())
                ->lockForUpdate()
                ->pluck('id');

            if ($displaced->isNotEmpty()) {
                Log::warning('Reassigning a Cav milpac id; clearing it from its previous holder.', [
                    'cav_id' => $cavId,
                    'from_user_ids' => $displaced->all(),
                    'to_user_id' => $user->getKey(),
                ]);

                User::whereIn('id', $displaced)->update(['cav_id' => null]);
            }

            $user->update(['cav_id' => $cavId]);
        });
    }
}
