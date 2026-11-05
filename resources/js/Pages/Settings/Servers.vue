<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { PhArrowsClockwise, PhCheckCircle, PhLockSimple, PhWarningCircle, PhHardDrives, PhPlugsConnected } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import EmptyState from '@/components/EmptyState.vue';
import { toast } from 'vue-sonner';
import { onHttpException } from '@/lib/httpError';
import { forumDateTime } from '@/lib/forumDate';

// The game servers the telemetry APIs list (#389). An Admin fetches them, then selects each one the portal should
// know about, assigns it a game and marks it training or public; only selected training servers are read for
// attendance. The top card shows what each game reads and how each source last went (#183), recorded by the capture
// runs and by Test connection, never probed on page load, so an Admin can tell "the feed is down" from "nobody played".
const props = defineProps({
    // `[{ id, interface, externalId, name, gameId, type, selected, fallbackId, missing, credentialsSet }]`.
    servers: { type: Array, required: true },
    // `[{ id, name, retired, interface, readsEnvServer, captureConfigured, connections: [{ label, sources: [{ interface,
    // configured, lastSucceededAt, lastFailedAt, lastFailureReason }] }] }]`, in the order they were added.
    games: { type: Array, required: true },
    // `[{ value, label, credentialsSet }]`: the interfaces a server can be listed by.
    interfaces: { type: Array, required: true },
    // `[{ value, label }]`: training, public.
    types: { type: Array, required: true },
    // What the last fetch found per interface, flashed once: `[{ interface, status, listed?, added?, missing?, message? }]`,
    // `status` one of listed, not_configured, failed, empty.
    fetchResult: { type: Array, default: null },
    // The last connection test, flashed once: `{ gameId, outcomes: [{ server, interface, status, reason? }] }`,
    // `status` one of ok, failed, not_configured.
    testResult: { type: Object, default: null },
});

// `interfaces` leaves out none, so a game read by none falls back to this label.
const interfaceLabel = (value) => props.interfaces.find((option) => option.value === value)?.label ?? (value === 'none' ? 'None' : value);
const typeLabel = (value) => props.types.find((option) => option.value === value)?.label ?? value;

const selectedFor = (game) => props.servers.filter((server) => server.selected && server.gameId === game.id);
// A CRCON server's fallback is a selected BattleMetrics server: the same game server, tracked there.
const fallbackOptions = computed(() => props.servers.filter((server) => server.interface === 'battlemetrics' && server.selected));

// Each row's choices, edited in place and saved with the row's own button. When the server sends the list again (after
// a save or a fetch), a new server gets a row and a row whose stored choices changed shows them; any other row keeps
// what the Admin has typed but not yet saved.
const stored = (server) => ({ selected: server.selected, game_id: server.gameId, type: server.type, fallback_server_id: server.fallbackId });
const drafts = reactive({});
watch(
    () => props.servers,
    (servers, previous = []) => {
        const before = Object.fromEntries(previous.map((server) => [server.id, JSON.stringify(stored(server))]));

        for (const id of Object.keys(drafts)) {
            if (!servers.some((server) => String(server.id) === id)) delete drafts[id];
        }
        for (const server of servers) {
            if (!drafts[server.id] || before[server.id] !== JSON.stringify(stored(server))) drafts[server.id] = stored(server);
        }
    },
    { immediate: true },
);
const rowErrors = ref({});
const fetching = ref(false);

function changed(server) {
    const draft = drafts[server.id];

    return (
        draft.selected !== server.selected ||
        draft.game_id !== server.gameId ||
        draft.type !== server.type ||
        draft.fallback_server_id !== server.fallbackId
    );
}

function save(server) {
    rowErrors.value = { ...rowErrors.value, [server.id]: null };
    router.patch(`/settings/servers/${server.id}`, { ...drafts[server.id] }, {
        preserveScroll: true,
        onError: (errors) => {
            rowErrors.value = {
                ...rowErrors.value,
                [server.id]: errors.server ?? errors.game_id ?? errors.type ?? errors.fallback_server_id ?? `${server.name} was not saved. Reload the page and try again.`,
            };
        },
        onHttpException: onHttpException(`${server.name} was not saved`, (message) => toast.error(message)),
    });
}

function fetchServers() {
    fetching.value = true;
    router.post('/settings/servers/fetch', {}, {
        preserveScroll: true,
        onFinish: () => (fetching.value = false),
        onHttpException: onHttpException('The servers were not fetched', (message) => toast.error(message)),
    });
}

function fetchLine(outcome) {
    const name = interfaceLabel(outcome.interface);

    if (outcome.status === 'not_configured') return `${name}: credentials not set, not asked.`;
    if (outcome.status === 'empty') return `${name}: listed no servers, so its stored servers were left as they are.`;
    if (outcome.status === 'failed') return `${name}: could not be read. ${outcome.message} Stored servers were left as they are.`;

    const parts = [`${outcome.listed} listed`];
    if (outcome.added) parts.push(`${outcome.added} new`);
    if (outcome.missing) parts.push(`${outcome.missing} no longer listed`);

    return `${name}: ${parts.join(', ')}.`;
}

const zulu = (at) => `${forumDateTime(at)}Z`;
const testing = ref(null);

// The failure is red when it is the newer of the two, so a source that failed and then answered reads as working.
const failingNow = (source) => source.lastFailedAt !== null && (source.lastSucceededAt === null || source.lastFailedAt > source.lastSucceededAt);

function testConnection(game) {
    testing.value = game.id;
    router.post(`/settings/servers/games/${game.id}/test`, {}, {
        preserveScroll: true,
        onFinish: () => (testing.value = null),
        onHttpException: onHttpException(`${game.name} was not tested`, (message) => toast.error(message)),
    });
}

function outcomeLine(outcome) {
    const name = `${outcome.server} (${interfaceLabel(outcome.interface)})`;

    if (outcome.status === 'not_configured') return `${name}: credentials not set, not read.`;
    if (outcome.status === 'no_server') return `${name}: no server selected, not read. Select a training server for this game.`;
    if (outcome.status === 'failed') return `${name}: could not be read. ${outcome.reason}`;

    return `${name}: answered.`;
}
</script>

<template>
    <Head title="Settings · Servers · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Servers</h1>
                <p class="mt-1 max-w-prose text-[13px] text-muted-foreground">
                    The game servers the portal can read. Only a selected training server is read for attendance; a public one
                    never is.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Badge variant="outline" class="gap-1"><PhLockSimple aria-hidden="true" />Admin</Badge>
                <Button data-testid="fetch-servers" :disabled="fetching" @click="fetchServers">
                    <PhArrowsClockwise aria-hidden="true" />{{ fetching ? 'Fetching' : 'Fetch servers' }}
                </Button>
            </div>
        </div>

        <Card v-if="fetchResult" class="gap-1 p-4" data-testid="fetch-result" role="status">
            <h2 class="text-[15px] font-semibold">Fetched</h2>
            <p
                v-for="outcome in fetchResult"
                :key="outcome.interface"
                :data-testid="`fetch-${outcome.interface}`"
                class="text-[13px]"
                :class="outcome.status === 'failed' ? 'text-destructive' : 'text-muted-foreground'"
            >
                {{ fetchLine(outcome) }}
            </p>
        </Card>

        <div class="flex flex-wrap gap-2" data-testid="credentials">
            <Badge
                v-for="option in interfaces"
                :key="option.value"
                :variant="option.credentialsSet ? 'secondary' : 'outline'"
                :data-testid="`credentials-${option.value}`"
                class="gap-1"
            >
                <PhCheckCircle v-if="option.credentialsSet" aria-hidden="true" />
                <PhWarningCircle v-else aria-hidden="true" />
                {{ option.label }} · {{ option.credentialsSet ? 'credentials set' : 'credentials not set' }}
            </Badge>
        </div>

        <Card class="min-w-0 gap-0 py-0">
            <div class="px-4 pt-3.5 pb-2">
                <h2 class="text-[15px] font-semibold">What each game reads</h2>
            </div>
            <div class="divide-y divide-border border-t border-border">
                <div v-for="game in games" :key="game.id" class="px-4 py-3" :data-testid="`game-${game.id}`">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="flex flex-wrap items-center gap-1.5 text-[13px] font-medium">
                            {{ game.name }}
                            <Badge v-if="game.retired" variant="outline">Retired</Badge>
                            <span class="font-normal text-muted-foreground" :data-testid="`interface-${game.id}`">{{ interfaceLabel(game.interface) }}</span>
                            <Badge :variant="game.captureConfigured ? 'secondary' : 'outline'" :data-testid="`configured-${game.id}`">
                                {{ game.captureConfigured ? 'Configured' : 'Not configured' }}
                            </Badge>
                        </p>
                        <Button
                            v-if="game.connections.length"
                            size="sm"
                            variant="outline"
                            :disabled="testing !== null"
                            :data-testid="`test-${game.id}`"
                            @click="testConnection(game)"
                        >
                            <PhPlugsConnected aria-hidden="true" />{{ testing === game.id ? 'Testing' : 'Test connection' }}
                        </Button>
                    </div>
                    <ul v-if="selectedFor(game).length" class="mt-1.5 space-y-1">
                        <li
                            v-for="server in selectedFor(game)"
                            :key="server.id"
                            :data-testid="`reads-${server.id}`"
                            class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13px]"
                        >
                            <span>{{ server.name }}</span>
                            <Badge :variant="server.type === 'training' ? 'secondary' : 'outline'">{{ typeLabel(server.type) }}</Badge>
                            <span class="text-muted-foreground">{{ interfaceLabel(server.interface) }}</span>
                            <span v-if="!server.credentialsSet" class="text-destructive">credentials not set</span>
                            <span v-if="server.missing" class="text-destructive">no longer listed</span>
                        </li>
                    </ul>
                    <p v-else class="mt-1 text-[13px] text-muted-foreground" :data-testid="`env-${game.id}`">
                        <template v-if="game.readsEnvServer">No server selected: reads the {{ interfaceLabel(game.interface) }} server set in env.</template>
                        <template v-else-if="game.interface === 'none'">Attendance read by none: leaders mark it by hand.</template>
                        <template v-else>No training server selected: nothing is read.</template>
                    </p>

                    <ul v-if="game.connections.length" class="mt-1.5 space-y-1 border-l-2 border-border pl-2.5">
                        <template v-for="connection in game.connections" :key="connection.label">
                            <li
                                v-for="source in connection.sources"
                                :key="`${connection.label}-${source.interface}`"
                                :data-testid="`source-${game.id}-${source.interface}`"
                                class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13px]"
                            >
                                <span>{{ connection.label }}</span>
                                <span class="text-muted-foreground">{{ interfaceLabel(source.interface) }}</span>
                                <span v-if="!source.configured && source.noServer" class="text-destructive">no server selected: select a training server</span>
                                <span v-else-if="!source.configured" class="text-destructive">credentials not set</span>
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

        <Card class="min-w-0 gap-0 py-0">
            <div class="px-4 pt-3.5 pb-2">
                <h2 class="text-[15px] font-semibold">Fetched servers</h2>
            </div>
            <Table v-if="servers.length" class="text-[13px]">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Selected</TableHead>
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">Server</TableHead>
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">Game</TableHead>
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">Type</TableHead>
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">Fallback</TableHead>
                        <TableHead class="h-8 px-4"><span class="sr-only">Actions</span></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-for="server in servers" :key="server.id">
                        <TableRow :data-testid="`server-row-${server.id}`">
                            <TableCell class="px-4">
                                <input
                                    v-model="drafts[server.id].selected"
                                    type="checkbox"
                                    class="size-4 accent-primary"
                                    :aria-label="`Select ${server.name}`"
                                    :data-testid="`selected-${server.id}`"
                                />
                            </TableCell>
                            <TableCell>
                                <span class="flex flex-wrap items-center gap-1.5 font-medium">
                                    {{ server.name }}
                                    <Badge v-if="server.missing" variant="outline" :data-testid="`missing-${server.id}`">No longer listed</Badge>
                                </span>
                                <span class="block text-xs text-muted-foreground">{{ interfaceLabel(server.interface) }} · <span class="font-mono">{{ server.externalId }}</span></span>
                            </TableCell>
                            <TableCell>
                                <NativeSelect v-model="drafts[server.id].game_id" :aria-label="`Game of ${server.name}`" :data-testid="`game-${server.id}-select`">
                                    <option :value="null">No game</option>
                                    <option v-for="game in games" :key="game.id" :value="game.id" :disabled="game.retired && game.id !== server.gameId">
                                        {{ game.name }}{{ game.retired ? ' (retired)' : '' }}
                                    </option>
                                </NativeSelect>
                            </TableCell>
                            <TableCell>
                                <NativeSelect v-model="drafts[server.id].type" :aria-label="`Type of ${server.name}`" :data-testid="`type-${server.id}`">
                                    <option v-for="type in types" :key="type.value" :value="type.value">{{ type.label }}</option>
                                </NativeSelect>
                            </TableCell>
                            <TableCell>
                                <NativeSelect
                                    v-if="server.interface === 'crcon'"
                                    v-model="drafts[server.id].fallback_server_id"
                                    :aria-label="`Fallback of ${server.name}`"
                                    :data-testid="`fallback-${server.id}`"
                                >
                                    <option :value="null">No fallback</option>
                                    <option v-for="option in fallbackOptions" :key="option.id" :value="option.id">{{ option.name }}</option>
                                </NativeSelect>
                                <span v-else class="text-muted-foreground">—</span>
                            </TableCell>
                            <TableCell class="px-4 text-right">
                                <Button size="sm" variant="outline" :disabled="!changed(server)" :data-testid="`save-${server.id}`" @click="save(server)">Save</Button>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="rowErrors[server.id]" class="hover:bg-transparent">
                            <TableCell colspan="6" class="px-4 pt-0 text-xs text-destructive" role="alert" :data-testid="`row-error-${server.id}`">
                                {{ rowErrors[server.id] }}
                            </TableCell>
                        </TableRow>
                    </template>
                </TableBody>
            </Table>
            <EmptyState v-else :icon="PhHardDrives" title="No servers fetched yet" class="border-t border-border py-10">
                Choose Fetch servers to list the servers each telemetry service with credentials can see.
            </EmptyState>
        </Card>
    </div>
</template>
