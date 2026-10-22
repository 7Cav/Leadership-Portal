<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { PhGameController, PhLockSimple, PhUsersThree } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import EmptyState from '@/components/EmptyState.vue';
import { onHttpException } from '@/lib/httpError';
import { cn } from '@/lib/utils';

// The link-identity screen (Story 7.2), binding design mockups/v2/link-identity.html variant C
// "Needs-a-link queue": a coverage summary, a queue of the troopers still missing a game, and a
// side panel for the one picked. A trooper holds one identity per platform (AD-19); the slots come
// from `platforms`, so a new game appears here without a change to this page.
const props = defineProps({
    // `[{ value, label, idLabel }]` — one per supported game.
    platforms: { type: Array, required: true },
    // `[{ id, displayName, billet, section, editable, identities: { [platform]: { playerName, platformId } } }]`
    // — the troopers in the viewer's scope, by name. `editable` is false for one the viewer only sees
    // through a staff grant.
    troopers: { type: Array, required: true },
});

const page = usePage();

// A view-as preview is read-only (Story 1.13), and so is a trooper the viewer only sees through a
// staff grant (`editable` false): the server refuses those writes, so say so up front.
const viewAs = computed(() => page.props.viewAs ?? null);

const view = ref('needs');
const selectedId = ref(null);
const platform = ref(props.platforms[0]?.value ?? '');
const removing = ref(null);
const removeProcessing = ref(false);
const notice = ref(null);

const form = useForm({ player_name: '', platform_id: '' });

const missingOf = (trooper) => props.platforms.filter((p) => !trooper.identities[p.value]);
const linkedOf = (trooper) => props.platforms.filter((p) => trooper.identities[p.value]);
const platformOf = (value) => props.platforms.find((p) => p.value === value);

const needsLink = computed(() => props.troopers.filter((trooper) => missingOf(trooper).length));
const queue = computed(() => (view.value === 'needs' ? needsLink.value : props.troopers));

const slots = computed(() => props.troopers.length * props.platforms.length);
const linked = computed(() => props.troopers.reduce((sum, trooper) => sum + linkedOf(trooper).length, 0));
const percent = computed(() => (slots.value ? Math.round((linked.value / slots.value) * 100) : 0));

const selected = computed(() => props.troopers.find((trooper) => trooper.id === selectedId.value) ?? null);
const platformMeta = computed(() => platformOf(platform.value));
const existing = computed(() => selected.value?.identities[platform.value] ?? null);
const editable = computed(() => !viewAs.value && Boolean(selected.value?.editable));

function joinNames(list) {
    const names = list.map((p) => p.label);
    return names.length > 1 ? `${names.slice(0, -1).join(', ')} and ${names.at(-1)}` : (names[0] ?? '');
}

function fill(trooper, value) {
    platform.value = value;
    removing.value = null;
    form.clearErrors();
    form.player_name = trooper.identities[value]?.playerName ?? '';
    form.platform_id = trooper.identities[value]?.platformId ?? '';
}

// Open the panel on a trooper: the first game they are missing, or their first game when full.
function open(trooper) {
    selectedId.value = trooper.id;
    fill(trooper, (missingOf(trooper)[0] ?? props.platforms[0]).value);
}

function close() {
    notice.value = null;
    selectedId.value = null;
    removing.value = null;
    form.reset();
    form.clearErrors();
}

function setView(value) {
    view.value = value;
}

// The trooper left the list while the panel was open (moved or departed since the page loaded).
function gone() {
    close();
    notice.value = 'That trooper is no longer in your scope.';
}

function save() {
    const trooper = selected.value;
    if (!trooper || !editable.value) return;

    form.put(`/game-identities/${trooper.id}/${platform.value}`, {
        preserveScroll: true,
        onSuccess: () => {
            // The props are fresh: carry on to the next missing game, or finish with this trooper.
            notice.value = null;
            const fresh = props.troopers.find((t) => t.id === trooper.id);
            if (!fresh) return gone();
            const next = missingOf(fresh)[0];
            if (next) fill(fresh, next.value);
            else if (view.value === 'needs') close();
            else fill(fresh, platform.value);
        },
        onError: (errors) => {
            // Validation and collisions show on their fields; anything else (a refused or expired
            // request) gets a message of its own rather than a silent form.
            if (!errors.player_name && !errors.platform_id) notice.value = 'This could not be saved. Reload the page and try again.';
        },
        // A refused or expired request is an HTTP exception, not a validation error: it says why in the same notice.
        onHttpException: onHttpException('This was not saved', (message) => (notice.value = message)),
    });
}

function remove(value) {
    const trooper = selected.value;
    if (!trooper || !editable.value) return;

    if (removeProcessing.value) return;
    removeProcessing.value = true;

    router.delete(`/game-identities/${trooper.id}/${value}`, {
        preserveScroll: true,
        onSuccess: () => {
            notice.value = null;
            removing.value = null;
            const fresh = props.troopers.find((t) => t.id === trooper.id);
            if (fresh) fill(fresh, value);
            else gone();
        },
        onError: () => (notice.value = 'This could not be removed. Reload the page and try again.'),
        onFinish: () => (removeProcessing.value = false),
    });
}
</script>

<template>
    <Head title="Game identities · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Game identities</h1>
                <p class="mt-1 text-[13px] text-muted-foreground">
                    Linked once, used to pre-mark attendance from the game servers. Troopers without a link are marked by hand on that game.
                </p>
            </div>
            <Badge variant="outline" class="gap-1" data-testid="scope-count">
                <PhUsersThree aria-hidden="true" />{{ troopers.length }} {{ troopers.length === 1 ? 'trooper' : 'troopers' }} in your scope
            </Badge>
        </div>

        <p v-if="notice" role="alert" class="rounded-md bg-destructive/10 px-3 py-2 text-[13px] text-destructive" data-testid="notice">{{ notice }}</p>

        <EmptyState v-if="!troopers.length" :icon="PhGameController" title="No troopers in your scope." class="rounded-lg border border-border py-10">
            Troopers placed in the units you command appear here, ready to link to their game identities.
        </EmptyState>

        <Card v-else class="min-w-0 gap-0 overflow-hidden py-0">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-3 border-b border-border px-4 py-3.5" data-testid="coverage">
                <p class="text-[13px] text-muted-foreground">
                    <b class="text-xl font-semibold text-foreground" data-testid="coverage-linked">{{ linked }}</b>
                    of <span data-testid="coverage-slots">{{ slots }}</span> game slots linked
                </p>
                <div
                    class="h-2 min-w-[140px] flex-1 basis-56 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    aria-label="Game slots linked"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuenow="percent"
                >
                    <div class="h-full bg-success transition-all" :style="{ width: `${percent}%` }" />
                </div>
                <div class="flex gap-1" role="group" aria-label="View">
                    <Button
                        v-for="option in [
                            { value: 'needs', label: 'Needs a link', count: needsLink.length },
                            { value: 'everyone', label: 'Everyone', count: troopers.length },
                        ]"
                        :key="option.value"
                        variant="outline"
                        size="sm"
                        :data-testid="`view-${option.value}`"
                        :aria-pressed="view === option.value"
                        :class="view === option.value ? 'bg-accent text-accent-foreground' : 'text-muted-foreground'"
                        @click="setView(option.value)"
                    >
                        {{ option.label }} <span class="tabular-nums">{{ option.count }}</span>
                    </Button>
                </div>
            </div>

            <div class="grid lg:grid-cols-[minmax(0,1fr)_340px]">
                <div class="min-w-0 py-1" data-testid="queue">
                    <EmptyState v-if="!queue.length" :icon="PhGameController" title="Everyone is linked." class="py-10" data-testid="queue-empty">
                        Every trooper in your scope has an identity on every game.
                    </EmptyState>
                    <ul v-else class="divide-y divide-border">
                        <li
                            v-for="trooper in queue"
                            :key="trooper.id"
                            :data-testid="`row-${trooper.id}`"
                            :class="cn('flex items-center justify-between gap-3 px-4 py-2.5', selectedId === trooper.id && 'bg-muted shadow-[inset_3px_0_0_var(--primary)]')"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-[13px] font-medium">{{ trooper.displayName }}</p>
                                <p class="truncate text-xs text-muted-foreground">
                                    <template v-if="view === 'needs'">Missing {{ joinNames(missingOf(trooper)) }}</template>
                                    <template v-else-if="linkedOf(trooper).length">
                                        <span v-for="(p, index) in linkedOf(trooper)" :key="p.value">
                                            <template v-if="index">, </template>{{ p.label }}: {{ trooper.identities[p.value].playerName }}
                                        </span>
                                        <template v-if="missingOf(trooper).length"> · Missing {{ joinNames(missingOf(trooper)) }}</template>
                                    </template>
                                    <template v-else>Missing {{ joinNames(missingOf(trooper)) }}</template>
                                    <template v-if="trooper.section"> · {{ trooper.section }}</template>
                                </p>
                            </div>
                            <Badge v-if="selectedId === trooper.id" variant="secondary">Editing</Badge>
                            <Button v-else variant="outline" size="sm" :data-testid="`open-${trooper.id}`" @click="open(trooper)">
                                {{ missingOf(trooper).length ? 'Add identity' : 'Edit identities' }}
                            </Button>
                        </li>
                    </ul>
                </div>

                <aside class="border-t border-border bg-background p-4 lg:border-t-0 lg:border-l" data-testid="panel" :aria-label="selected ? `Game identities for ${selected.displayName}` : 'Game identity details'">
                    <EmptyState v-if="!selected" :icon="PhGameController" title="Pick a trooper." class="py-8">
                        Choose one from the list to add or change their game identities.
                    </EmptyState>

                    <div v-else class="grid content-start gap-3.5">
                        <div>
                            <h2 class="text-[15px] font-semibold" data-testid="panel-name">{{ selected.displayName }}</h2>
                            <p class="font-mono text-xs text-muted-foreground">{{ [selected.billet, selected.section].filter(Boolean).join(', ') }}</p>
                        </div>

                        <p v-if="viewAs" class="flex items-center gap-1.5 text-xs text-muted-foreground" data-testid="read-only">
                            <PhLockSimple aria-hidden="true" />Read-only while viewing as {{ viewAs.label }}
                        </p>
                        <p v-else-if="!editable" class="flex items-center gap-1.5 text-xs text-muted-foreground" data-testid="read-only">
                            <PhLockSimple aria-hidden="true" />Read-only: a staff grant lets you view this trooper, not change their links
                        </p>

                        <ul v-if="linkedOf(selected).length" class="grid gap-2" data-testid="identities">
                            <li
                                v-for="p in linkedOf(selected)"
                                :key="p.value"
                                :data-testid="`identity-${p.value}`"
                                class="rounded-md border border-border bg-card px-2.5 py-2"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-xs text-muted-foreground">{{ p.label }}</p>
                                        <p class="truncate text-[13px] font-medium">{{ selected.identities[p.value].playerName }}</p>
                                        <p class="truncate font-mono text-xs text-muted-foreground">{{ selected.identities[p.value].platformId }}</p>
                                    </div>
                                    <div v-if="removing !== p.value" class="flex shrink-0 gap-1">
                                        <Button variant="outline" size="xs" :data-testid="`edit-${p.value}`" @click="fill(selected, p.value)">Edit</Button>
                                        <Button
                                            variant="outline"
                                            size="xs"
                                            class="text-destructive hover:text-destructive"
                                            :data-testid="`remove-${p.value}`"
                                            :disabled="!editable"
                                            @click="removing = p.value"
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                </div>
                                <div v-if="removing === p.value" class="mt-2 flex flex-wrap items-center gap-2 text-xs" data-testid="remove-confirm">
                                    <span class="text-muted-foreground">Remove this link? Past attendance is unchanged.</span>
                                    <Button variant="destructive" size="xs" :data-testid="`confirm-remove-${p.value}`" :disabled="removeProcessing" @click="remove(p.value)">Remove</Button>
                                    <Button variant="outline" size="xs" @click="removing = null">Keep</Button>
                                </div>
                            </li>
                        </ul>

                        <form class="grid gap-3" data-testid="identity-form" @submit.prevent="save">
                            <div class="grid gap-1.5">
                                <Label for="identity-game">Game</Label>
                                <NativeSelect id="identity-game" :model-value="platform" data-testid="identity-game" @update:model-value="fill(selected, $event)">
                                    <option v-for="p in platforms" :key="p.value" :value="p.value">{{ p.label }}{{ selected.identities[p.value] ? ' (linked)' : '' }}</option>
                                </NativeSelect>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="identity-name">Player name</Label>
                                <Input id="identity-name" v-model="form.player_name" data-testid="identity-name" autocomplete="off" :aria-invalid="!!form.errors.player_name" />
                                <p v-if="form.errors.player_name" class="text-xs text-destructive" data-testid="error-player_name">{{ form.errors.player_name }}</p>
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="identity-id">{{ platformMeta?.idLabel }}</Label>
                                <Input
                                    id="identity-id"
                                    v-model="form.platform_id"
                                    data-testid="identity-id"
                                    autocomplete="off"
                                    class="font-mono"
                                    :aria-invalid="!!form.errors.platform_id"
                                    :aria-describedby="form.errors.platform_id ? 'identity-id-error' : undefined"
                                />
                                <p
                                    v-if="form.errors.platform_id"
                                    id="identity-id-error"
                                    role="alert"
                                    class="rounded-md bg-destructive/10 px-2.5 py-1.5 text-[13px] text-destructive"
                                    data-testid="error-platform_id"
                                >
                                    {{ form.errors.platform_id }}
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <Button type="submit" data-testid="identity-save" :disabled="form.processing || !editable">{{ existing ? 'Save changes' : 'Save identity' }}</Button>
                                <Button type="button" variant="outline" data-testid="identity-cancel" @click="close">Cancel</Button>
                            </div>
                        </form>
                    </div>
                </aside>
            </div>
        </Card>
    </div>
</template>
