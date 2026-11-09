<?php

namespace App\Tutorial;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The tutorial's isolation layer. While it is entered, the whole request runs on one person's own practice database (a
 * SQLite file of their own under `portal.tutorial_path`) instead of the portal's database, and every way out of the
 * portal is swapped for a fake that sends nothing:
 *
 *  - the default database connection is the practice file, so every Repository, the audit envelope and the activity
 *    log write there. The real connection carries a guard that throws {@see RealDatabaseTouched} on any query while
 *    the sandbox is entered, so a leak fails loudly instead of writing;
 *  - jobs (the Sheet push, the Drive upload, syncs), queues, mail and notifications go to Laravel's fakes and are
 *    dropped;
 *  - every Saloon connector (forum, Cav, CRCON, BattleMetrics, Nightwatch, Steam, Google) answers from a global mock
 *    with a 503, so a handler sees the outside world as unavailable; Laravel's HTTP client refuses stray requests;
 *  - the application cache is a fresh array store, so nothing cached for the real portal is read or written.
 *
 * Which file is used comes only from the signed-in user's id, never from the request, so a person can never open
 * someone else's practice copy. {@see leave()} puts everything back; it runs when the request ends.
 */
final class TutorialSandbox
{
    public const CONNECTION = 'tutorial';

    private const BUILD_CONNECTION = 'tutorial_build';

    /** Read by the real connection's guard, which is installed once per connection object. */
    private static bool $entered = false;

    private ?int $userId = null;

    private ?string $realConnection = null;

    private ?string $cacheDriver = null;

    /** @var array<string, mixed> */
    private array $swapped = [];

    private ?MockClient $previousMock = null;

    /** @var array<string, true> */
    private array $guarded = [];

    /** @var list<string> connector calls the mock answered in place of the outside world */
    private array $swallowed = [];

    public function __construct(private readonly Application $app) {}

    public function entered(): bool
    {
        return self::$entered;
    }

    public function userId(): ?int
    {
        return self::$entered ? $this->userId : null;
    }

    public function directory(): string
    {
        return rtrim((string) config('portal.tutorial_path'), '/');
    }

    public function path(int $userId): string
    {
        return $this->directory()."/user-{$userId}.sqlite";
    }

    public function exists(int $userId): bool
    {
        return is_file($this->path($userId));
    }

    /** A fresh, empty practice copy for the user: the migrated template copied over whatever was there. */
    public function prepare(int $userId): void
    {
        if (self::$entered) {
            throw new LogicException('A practice copy cannot be replaced from inside the tutorial.');
        }

        $template = $this->template();
        $target = $this->path($userId);
        $temporary = $target.'.'.bin2hex(random_bytes(4)).'.tmp';
        copy($template, $temporary);
        rename($temporary, $target);
    }

    public function discard(int $userId): void
    {
        if (self::$entered && $this->userId === $userId) {
            throw new LogicException('The practice copy in use cannot be removed.');
        }

        if (is_file($this->path($userId))) {
            unlink($this->path($userId));
        }
    }

    /**
     * Remove the practice copies nobody used for `$days` days: a tutorial left without Exit (the session ran out). Each
     * write to a copy touches its file. Returns how many were removed.
     */
    public function prune(int $days = 2): int
    {
        $removed = 0;
        $before = time() - $days * 86400;

        foreach (glob($this->directory().'/user-*.sqlite') ?: [] as $file) {
            if (filemtime($file) < $before && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * The migrated empty database every practice copy starts from, built once per set of migrations (the name carries
     * a hash of them, so a deploy with a new migration builds a new one and removes the old).
     */
    public function template(): string
    {
        $migrations = glob(database_path('migrations/*.php')) ?: [];
        sort($migrations);
        $hash = substr(sha1(implode('|', array_map('basename', $migrations))), 0, 12);
        $path = $this->directory()."/template-{$hash}.sqlite";

        if (is_file($path)) {
            return $path;
        }

        if (! is_dir($this->directory())) {
            mkdir($this->directory(), 0775, true);
        }

        $building = $path.'.'.bin2hex(random_bytes(4)).'.building';
        touch($building);
        config(['database.connections.'.self::BUILD_CONNECTION => self::sqlite($building)]);

        try {
            Artisan::call('migrate', ['--database' => self::BUILD_CONNECTION, '--force' => true]);
        } finally {
            DB::purge(self::BUILD_CONNECTION);
        }

        rename($building, $path);

        foreach (glob($this->directory().'/template-*.sqlite') ?: [] as $old) {
            if ($old !== $path) {
                @unlink($old);
            }
        }

        return $path;
    }

    /**
     * Run the rest of the request on the user's practice copy, with every outbound path faked. The copy must exist
     * ({@see prepare()}).
     */
    public function enter(int $userId): void
    {
        if (self::$entered) {
            throw new LogicException('The tutorial is already entered on this request.');
        }

        if (! $this->exists($userId)) {
            throw new LogicException('There is no practice copy for this user.');
        }

        $this->realConnection = DB::getDefaultConnection();
        $this->guard($this->realConnection);

        config(['database.connections.'.self::CONNECTION => self::sqlite($this->path($userId))]);
        DB::purge(self::CONNECTION);
        DB::setDefaultConnection(self::CONNECTION);

        $cache = $this->app->make('cache');
        $this->cacheDriver = $cache->getDefaultDriver();
        $cache->forgetDriver('array');
        $cache->setDefaultDriver('array');
        $this->app->forgetInstance('cache.store');

        $this->swapped = [
            Bus::class => Bus::getFacadeRoot(),
            Queue::class => Queue::getFacadeRoot(),
            Mail::class => Mail::getFacadeRoot(),
            Notification::class => Notification::getFacadeRoot(),
        ];
        Bus::fake();
        Queue::fake();
        Mail::fake();
        Notification::fake();

        $this->previousMock = MockClient::getGlobal();
        MockClient::destroyGlobal();
        MockClient::global(['*' => function () {
            $this->swallowed[] = 'http';

            return MockResponse::make(['message' => 'Not sent: this is the tutorial.'], 503);
        }]);
        Http::preventStrayRequests();

        $this->userId = $userId;
        self::$entered = true;
    }

    /** Back to the real portal. Safe to call when not entered. */
    public function leave(): void
    {
        if (! self::$entered) {
            return;
        }

        self::$entered = false;
        $this->userId = null;

        DB::purge(self::CONNECTION);
        DB::setDefaultConnection((string) $this->realConnection);

        $cache = $this->app->make('cache');
        $cache->forgetDriver('array');
        $cache->setDefaultDriver((string) $this->cacheDriver);
        $this->app->forgetInstance('cache.store');

        foreach ($this->swapped as $facade => $root) {
            $facade::swap($root);
        }
        $this->swapped = [];

        MockClient::destroyGlobal();
        if ($this->previousMock !== null) {
            $this->restoreMock($this->previousMock);
        }
        $this->previousMock = null;
        Http::preventStrayRequests(false);
    }

    /**
     * Run `$work` on the user's practice copy and leave again, whatever happens.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function run(int $userId, callable $work): mixed
    {
        $this->enter($userId);

        try {
            return $work();
        } finally {
            $this->leave();
        }
    }

    /** How many connector calls the mock answered instead of the outside world, since the process started. */
    public function heldBackCalls(): int
    {
        return count($this->swallowed);
    }

    private function guard(string $connection): void
    {
        if (isset($this->guarded[$connection])) {
            return;
        }

        DB::connection($connection)->beforeExecuting(function (string $query) use ($connection) {
            if (self::$entered) {
                throw new RealDatabaseTouched($connection, $query);
            }
        });
        $this->guarded[$connection] = true;
    }

    private function restoreMock(MockClient $mock): void
    {
        // MockClient::global() makes a new client; put the caller's own one back (a test's) as it was.
        $property = new \ReflectionProperty(MockClient::class, 'globalMockClient');
        $property->setValue(null, $mock);
    }

    /** @return array<string, mixed> */
    private static function sqlite(string $path): array
    {
        return [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'wal',
        ];
    }
}
