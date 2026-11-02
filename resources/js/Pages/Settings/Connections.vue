<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { PhLockSimple, PhPlugsConnected } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { toast } from 'vue-sonner';
import { onHttpException } from '@/lib/httpError';
import { forumDateTime } from '@/lib/forumDate';

// Game server connections (#183): every game, the interface that reads it, and each source a capture would read with
// whether its credentials are set and how its last read went, so an Admin can tell "the feed is down" from "nobody
// played". The last reads are recorded by the capture runs and by Test connection, never probed on page load.
const props = defineProps({
    // `[{ id, name, retired, interface, configured, servers: [{ label, sources: [{ interface, configured,
    // lastSucceededAt, lastFailedAt, lastFailureReason }] }] }]`, in the order the games were added.
    games: { type: Array, required: true },
    // `[{ value, label }]`: every interface, `none` included.
    interfaces: { type: Array, required: true },
    // The last test, flashed once: `{ gameId, outcomes: [{ server, interface, status, reason? }] }`, `status` one of
    // ok, failed, not_configured.
    testResult: { type: Object, default: null },
});

const interfaceLabel = (value) => props.interfaces.find((option) => option.value === value)?.label ?? value;
const zulu = (at) => `${forumDateTime(at)}Z`;
const testing = ref(null);

// The failure is shown when it is the newer of the two, so a source that failed and then answered reads as working.
const failingNow = (source) => source.lastFailedAt !== null && (source.lastSucceededAt === null || source.lastFailedAt > source.lastSucceededAt);

function testConnection(game) {
    testing.value = game.id;
    router.post(`/settings/connections/${game.id}/test`, {}, {
        preserveScroll: true,
        onFinish: () => (testing.value = null),
        onHttpException: onHttpException(`${game.name} was not tested`, (message) => toast.error(message)),
    });
}

function outcomeLine(outcome) {
    const name = `${outcome.server} (${interfaceLabel(outcome.interface)})`;

    if (outcome.status === 'not_configured') return `${name}: credentials not set, not read.`;
    if (outcome.status === 'failed') return `${name}: could not be read. ${outcome.reason}`;

    return `${name}: answered.`;
}
</script>

<template>
    <Head title="Settings · Connections · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Game server connections</h1>
                <p class="mt-1 max-w-prose text-[13px] text-muted-foreground">
                    How the portal last read each game's servers, so a feed that is down does not pass for nobody playing.
                </p>
            </div>
            <Badge variant="outline" class="gap-1"><PhLockSimple aria-hidden="true" />Admin</Badge>
        </div>

        <Card class="min-w-0 gap-0 py-0">
            <div class="divide-y divide-border">
                <div v-for="game in games" :key="game.id" class="px-4 py-3" :data-testid="`game-${game.id}`">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="flex flex-wrap items-center gap-1.5 text-[13px] font-medium">
                            {{ game.name }}
                            <Badge v-if="game.retired" variant="outline">Retired</Badge>
                            <span class="font-normal text-muted-foreground" :data-testid="`interface-${game.id}`">{{ interfaceLabel(game.interface) }}</span>
                            <Badge :variant="game.configured ? 'secondary' : 'outline'" :data-testid="`configured-${game.id}`">
                                {{ game.configured ? 'Configured' : 'Not configured' }}
                            </Badge>
                        </p>
                        <Button
                            v-if="game.servers.length"
                            size="sm"
                            variant="outline"
                            :disabled="testing !== null"
                            :data-testid="`test-${game.id}`"
                            @click="testConnection(game)"
                        >
                            <PhPlugsConnected aria-hidden="true" />{{ testing === game.id ? 'Testing' : 'Test connection' }}
                        </Button>
                    </div>

                    <p v-if="game.interface === 'none'" class="mt-1 text-[13px] text-muted-foreground" :data-testid="`none-${game.id}`">
                        Attendance read by none: leaders mark it by hand.
                    </p>
                    <p v-else-if="!game.servers.length" class="mt-1 text-[13px] text-muted-foreground" :data-testid="`nothing-${game.id}`">
                        No training server selected: nothing is read.
                    </p>
                    <ul v-else class="mt-1.5 space-y-1">
                        <template v-for="server in game.servers" :key="server.label">
                            <li
                                v-for="source in server.sources"
                                :key="`${server.label}-${source.interface}`"
                                :data-testid="`source-${game.id}-${source.interface}`"
                                class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13px]"
                            >
                                <span>{{ server.label }}</span>
                                <span class="text-muted-foreground">{{ interfaceLabel(source.interface) }}</span>
                                <span v-if="!source.configured" class="text-destructive">credentials not set</span>
                                <span class="text-muted-foreground">
                                    {{ source.lastSucceededAt ? `Last read ${zulu(source.lastSucceededAt)}` : 'Never read' }}
                                </span>
                                <span v-if="source.lastFailedAt" :class="failingNow(source) ? 'text-destructive' : 'text-muted-foreground'">
                                    Last failed {{ zulu(source.lastFailedAt) }}: {{ source.lastFailureReason }}
                                </span>
                            </li>
                        </template>
                    </ul>

                    <div v-if="testResult && testResult.gameId === game.id" class="mt-2 space-y-0.5" role="status" :data-testid="`test-result-${game.id}`">
                        <p
                            v-for="(outcome, index) in testResult.outcomes"
                            :key="index"
                            class="text-[13px]"
                            :class="outcome.status === 'ok' ? 'text-muted-foreground' : 'text-destructive'"
                        >
                            {{ outcomeLine(outcome) }}
                        </p>
                    </div>
                </div>
            </div>
        </Card>
    </div>
</template>
