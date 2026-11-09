<?php

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

// A notification is deleted 14 days after it was read; an unread one stays however old it is.

function pnNotification(User $user, ?string $readAt, string $createdAt = '2026-10-01 12:00:00'): string
{
    $id = (string) Str::uuid();
    DatabaseNotification::query()->create([
        'id' => $id,
        'type' => 'test',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => ['message' => 'Hello'],
        'read_at' => $readAt,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

it('deletes notifications read more than 14 days ago and keeps newer reads and unread ones', function () {
    $this->travelTo('2026-11-09 12:00:00');
    $user = User::factory()->create();
    pnNotification($user, '2026-10-25 12:00:00');
    $recent = pnNotification($user, '2026-10-27 12:00:00');
    $unread = pnNotification($user, null);

    $this->artisan('portal:prune-notifications')->expectsOutputToContain('Deleted 1 notification read more than 14 days ago')->assertSuccessful();

    expect(DatabaseNotification::query()->pluck('id')->sort()->values()->all())->toBe(collect([$recent, $unread])->sort()->values()->all());
});

it('runs daily', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'portal:prune-notifications'));

    expect($event?->expression)->toBe('30 4 * * *');
});
