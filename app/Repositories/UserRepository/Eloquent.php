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

    public function nonAdminUsers(): Collection
    {
        return User::query()->where('is_admin', false)->orderBy('username')->get();
    }

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
