<?php

namespace App\Commands\Capture;

/**
 * One game server a capture must read (#389), with the sources that can answer for it in order: the server itself,
 * then its fallback (a CRCON server's BattleMetrics twin). The first that answers is the server's answer; when none
 * does, the server could not be read and neither can the practice, since a smaller list would under-report.
 * `$gameServerId` is null for the legacy single server read from env.
 */
final readonly class CaptureServer
{
    /**
     * @param  list<CaptureSource>  $sources
     */
    public function __construct(
        public ?int $gameServerId,
        public string $label,
        public array $sources,
    ) {}

    /**
     * The sources whose credentials are set, in order.
     *
     * @return list<CaptureSource>
     */
    public function configuredSources(): array
    {
        return array_values(array_filter($this->sources, fn (CaptureSource $source) => $source->configured));
    }
}
