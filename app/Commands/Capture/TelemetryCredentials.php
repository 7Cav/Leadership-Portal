<?php

namespace App\Commands\Capture;

use App\Models\TelemetryInterface;

/**
 * Whether each telemetry interface has its credentials set in env (AD-23), without ever handing them out: the
 * fetch skips an interface without them, a capture treats a server behind one as unreadable, and the Servers page
 * says which are missing (#389). The same test each seam applies before it will call out.
 */
final class TelemetryCredentials
{
    /** The env-backed keys each interface needs before it can be asked anything. */
    private const REQUIRED = ['base_url', 'api_key'];

    /**
     * @param  array<string, array<string, mixed>|null>  $configByInterface  keyed by {@see TelemetryInterface} value
     */
    public function __construct(private readonly array $configByInterface) {}

    public function areSet(TelemetryInterface $interface): bool
    {
        if ($interface === TelemetryInterface::None) {
            return false;
        }

        $config = $this->configByInterface[$interface->value] ?? null;

        foreach (self::REQUIRED as $key) {
            if (blank($config[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
