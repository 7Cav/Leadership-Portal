<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { PhGameController, PhLockSimple, PhPencilSimple, PhPlus } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import EmptyState from '@/components/EmptyState.vue';
import { toast } from 'vue-sonner';
import { onHttpException } from '@/lib/httpError';
import { splitTags } from '@/lib/gameTags';

// The games the regiment plays (#388): the list on the left, and the add/edit form alongside for an Admin (the
// Staff grants layout). A game in use can be retired, not deleted, so Delete is offered only for one nothing uses.
// An Admin also sees the identities saved before #450 that break its rules, to fix on Game identities.
// A game that asks for a Steam or Epic ID offers an Admin to apply the links troopers already have on other games
// (#174), one click and never on its own. Each game's calendar tags are the text in a 7Cav calendar event's title that
// marks it as the game's, so a section that plays it lists the event on its roll call (#751). Its forum calendars are
// where the forum files its operations, classes and FTXs (Operations / Battlefield 6): an event filed there is the
// game's whatever its title says, and the tags are only the fallback.
const props = defineProps({
    // `[{ id, slug, name, abbreviation, idKinds, checkedKinds, interface, eventTags, eventCalendars, retired, inUse, accounts, pendingAccountLinks }]`, in
    // the order they were added; `checkedKinds` are the kinds whose IDs the server checks for a shape, `accounts` the shared
    // accounts it asks for ("Steam", "Epic"), and `pendingAccountLinks` how many troopers the backfill would link (Admin only).
    games: { type: Array, required: true },
    // `[{ value, label }]`: the telemetry interfaces the portal has built in.
    interfaces: { type: Array, required: true },
    isAdmin: { type: Boolean, required: true },
    // Admin only: `[{ id, trooper, game, idKind, platformId, problems }]`, hidden characters written out.
    identitiesToCheck: { type: Array, default: () => [] },
});

const editingId = ref(null);
const editing = computed(() => props.games.find((game) => game.id === editingId.value) ?? null);
const rowError = ref(null);

// `id_kinds` is the list of player-ID kinds the game asks for ("Steam ID", "Epic ID"), the first being the default.
// `event_tags` is typed as one line, the tags separated by commas, and sent as a list. Each kind's forum calendars are
// typed the same way, as links or ids, and sent as `event_calendars: { operation: [...], class: [...], ftx: [...] }`.
const CALENDAR_KINDS = [
    { value: 'operation', label: 'Operations' },
    { value: 'class', label: 'Classes' },
    { value: 'ftx', label: 'FTX' },
];
const blankCalendars = () => Object.fromEntries(CALENDAR_KINDS.map((kind) => [kind.value, '']));
const form = useForm({ name: '', abbreviation: '', id_kinds: [''], interface: 'none', event_tags: '', event_calendars: blankCalendars() });

// "Operations 46, Classes 38" for a game's row, or null when it names no calendar.
function calendarsLine(game) {
    const parts = CALENDAR_KINDS.filter((kind) => game.eventCalendars?.[kind.value]?.length).map((kind) => `${kind.label} ${game.eventCalendars[kind.value].join(', ')}`);
    return parts.length ? parts.join(' · ') : null;
}

const interfaceLabel = (value) => props.interfaces.find((option) => option.value === value)?.label ?? value;

function edit(game) {
    editingId.value = game.id;
    form.clearErrors();
    form.name = game.name;
    form.abbreviation = game.abbreviation ?? '';
    form.id_kinds = [...game.idKinds];
    form.interface = game.interface;
    form.event_tags = (game.eventTags ?? []).join(', ');
    form.event_calendars = Object.fromEntries(CALENDAR_KINDS.map((kind) => [kind.value, (game.eventCalendars?.[kind.value] ?? []).join(', ')]));
}

const addKind = () => form.id_kinds.push('');
const removeKind = (index) => form.id_kinds.splice(index, 1);

// The server names a kind error by its row (`id_kinds.1`) or for the whole list (`id_kinds`, e.g. a kind in use).
const kindError = computed(() => Object.entries(form.errors).find(([key]) => key === 'id_kinds' || key.startsWith('id_kinds.'))?.[1]);
const tagError = computed(() => Object.entries(form.errors).find(([key]) => key === 'event_tags' || key.startsWith('event_tags.'))?.[1]);
// A calendar error, by kind (`event_calendars.ftx.0`), or for the whole set (`event_calendars`).
const calendarError = (kind) => Object.entries(form.errors).find(([key]) => key === `event_calendars.${kind}` || key.startsWith(`event_calendars.${kind}.`) || key === 'event_calendars')?.[1];

function cancelEdit() {
    editingId.value = null;
    form.clearErrors();
    form.reset();
    form.event_calendars = blankCalendars();
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => cancelEdit(),
        onHttpException: onHttpException(editing.value ? 'The game was not saved' : 'The game was not added', (message) => toast.error(message)),
    };

    const sent = form.transform((data) => ({
        ...data,
        event_tags: splitTags(data.event_tags),
        event_calendars: Object.fromEntries(CALENDAR_KINDS.map((kind) => [kind.value, splitTags(data.event_calendars[kind.value] ?? '')])),
    }));
    if (editing.value) sent.patch(`/settings/games/${editing.value.id}`, options);
    else sent.post('/settings/games', options);
}

// Retire, reinstate and delete are one click each: a retire is undone by Reinstate, and a delete is refused by the
// server for a game anything uses.
function act(method, url, failure) {
    rowError.value = null;
    router[method](url, {
        preserveScroll: true,
        onError: (errors) => {
            rowError.value = errors.game ?? `${failure}. Reload the page and try again.`;
        },
        onHttpException: onHttpException(failure, (message) => toast.error(message)),
    });
}

const retire = (game) => act('post', `/settings/games/${game.id}/retire`, `${game.name} was not retired`);
const reinstate = (game) => act('delete', `/settings/games/${game.id}/retire`, `${game.name} was not brought back`);
const remove = (game) => act('delete', `/settings/games/${game.id}`, `${game.name} was not deleted`);
const accountLabel = (game) => `Apply existing ${(game.accounts ?? []).join(' and ')} links`;
const applyAccountLinks = (game) => act('post', `/settings/games/${game.id}/account-links`, `The ${(game.accounts ?? []).join(' and ')} links were not applied to ${game.name}`);
</script>

<template>
    <Head title="Settings · Games · 7Cav Leadership Portal" />

    <div class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Games</h1>
                <p class="mt-1 text-[13px] text-muted-foreground">
                    The games troopers link an identity for, and which server feed reads each one's attendance.
                </p>
            </div>
            <Badge v-if="isAdmin" variant="outline" class="gap-1"><PhLockSimple aria-hidden="true" />Admin</Badge>
        </div>

        <div class="grid items-start gap-5 lg:grid-cols-[1.1fr_0.9fr]">
            <Card class="min-w-0 gap-0 py-0">
                <div class="px-4 pt-3.5 pb-2">
                    <h2 class="text-[15px] font-semibold">Games</h2>
                </div>
                <p v-if="rowError" data-testid="row-error" role="alert" class="px-4 pb-2 text-xs text-destructive">{{ rowError }}</p>

                <Table v-if="games.length" sticky-header class="text-[13px]">
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Game</TableHead>
                            <TableHead class="h-8 text-xs font-medium text-muted-foreground">Player ID kinds</TableHead>
                            <TableHead class="h-8 text-xs font-medium text-muted-foreground">Attendance read by</TableHead>
                            <TableHead class="h-8 px-4"><span class="sr-only">Actions</span></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="game in games" :key="game.id" :data-testid="`game-row-${game.slug}`">
                            <TableCell class="px-4 font-medium whitespace-normal">
                                <span class="flex flex-wrap items-center gap-1.5">
                                    {{ game.name }}
                                    <span
                                        v-if="game.abbreviation"
                                        :data-testid="`abbreviation-${game.slug}`"
                                        class="rounded-sm border border-border px-1 font-mono text-[10px] font-semibold tracking-wide text-muted-foreground"
                                        >{{ game.abbreviation }}</span
                                    >
                                    <Badge v-if="game.retired" variant="outline" :data-testid="`retired-${game.slug}`">Retired</Badge>
                                </span>
                                <span class="block text-xs font-normal text-muted-foreground" :data-testid="`event-tags-${game.slug}`">
                                    {{ game.eventTags?.length ? `Calendar tags: ${game.eventTags.join(', ')}` : 'No calendar tags' }}
                                </span>
                                <span class="block text-xs font-normal text-muted-foreground" :data-testid="`event-calendars-${game.slug}`">
                                    {{ calendarsLine(game) ? `Forum calendars: ${calendarsLine(game)}` : 'No forum calendars' }}
                                </span>
                            </TableCell>
                            <TableCell class="whitespace-normal">
                                <span :data-testid="`kinds-${game.slug}`">{{ game.idKinds.join(', ') }}</span>
                                <span v-if="game.checkedKinds?.length" class="block text-xs text-muted-foreground" :data-testid="`checked-kinds-${game.slug}`">
                                    Shape checked: {{ game.checkedKinds.join(', ') }}
                                </span>
                                <span v-if="isAdmin && !game.retired && game.pendingAccountLinks > 0" class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                    <Button
                                        variant="outline"
                                        size="xs"
                                        class="h-auto py-1 text-left whitespace-normal"
                                        :data-testid="`apply-account-links-${game.slug}`"
                                        @click="applyAccountLinks(game)"
                                    >
                                        {{ accountLabel(game) }}
                                    </Button>
                                    <span :data-testid="`pending-account-links-${game.slug}`">
                                        {{ game.pendingAccountLinks }} {{ game.pendingAccountLinks === 1 ? 'trooper' : 'troopers' }}
                                    </span>
                                </span>
                            </TableCell>
                            <TableCell class="whitespace-normal" :data-testid="`interface-${game.slug}`">{{ interfaceLabel(game.interface) }}</TableCell>
                            <TableCell class="px-4 whitespace-normal">
                                <!-- The actions wrap onto a second line rather than push the table past its card. -->
                                <div v-if="isAdmin" class="flex flex-wrap justify-end gap-x-1">
                                    <Button variant="ghost" size="sm" :data-testid="`edit-${game.slug}`" @click="edit(game)">Edit</Button>
                                    <Button v-if="game.retired" variant="ghost" size="sm" :data-testid="`reinstate-${game.slug}`" @click="reinstate(game)">
                                        Bring back
                                    </Button>
                                    <Button v-else variant="ghost" size="sm" :data-testid="`retire-${game.slug}`" @click="retire(game)">Retire</Button>
                                    <Button
                                        v-if="!game.inUse"
                                        variant="ghost"
                                        size="sm"
                                        class="text-destructive hover:text-destructive"
                                        :data-testid="`delete-${game.slug}`"
                                        @click="remove(game)"
                                    >
                                        Delete
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <EmptyState v-else :icon="PhGameController" title="No games yet" class="border-t border-border py-10">
                    {{ isAdmin ? 'Add the first game with the form alongside.' : 'An Admin can add the games here.' }}
                </EmptyState>
            </Card>

            <Card v-if="isAdmin" class="min-w-0 gap-0 p-4">
                <form data-testid="game-form" class="space-y-3" @submit.prevent="submit">
                    <h2 class="flex items-center gap-1.5 text-[15px] font-semibold">
                        <template v-if="editing"><PhPencilSimple aria-hidden="true" />Edit {{ editing.name }}</template>
                        <template v-else><PhPlus aria-hidden="true" />Add a game</template>
                    </h2>

                    <div class="grid gap-1.5">
                        <Label for="game-name">Name</Label>
                        <Input id="game-name" v-model="form.name" data-testid="game-name" autocomplete="off" :aria-invalid="!!form.errors.name" />
                        <p v-if="form.errors.name" class="text-xs text-destructive" data-testid="error-name">{{ form.errors.name }}</p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="game-abbreviation">Abbreviation</Label>
                        <Input
                            id="game-abbreviation"
                            :model-value="form.abbreviation"
                            data-testid="game-abbreviation"
                            class="w-28 font-mono uppercase"
                            maxlength="6"
                            autocomplete="off"
                            aria-describedby="game-abbreviation-help"
                            :aria-invalid="!!form.errors.abbreviation"
                            @update:model-value="(value) => (form.abbreviation = String(value).toUpperCase())"
                        />
                        <p id="game-abbreviation-help" class="text-xs text-muted-foreground">
                            2 to 6 letters or digits. Org structure shows it on the highest line unit in an AO that plays the game, usually a battalion.<template v-if="!editing"> Left blank, it is made from the name.</template>
                        </p>
                        <p v-if="form.errors.abbreviation" class="text-xs text-destructive" data-testid="error-abbreviation">{{ form.errors.abbreviation }}</p>
                    </div>

                    <div class="grid gap-1.5" role="group" aria-labelledby="game-id-kinds">
                        <Label id="game-id-kinds">Player ID kinds</Label>
                        <div v-for="(kind, index) in form.id_kinds" :key="index" class="flex gap-2">
                            <Input
                                v-model="form.id_kinds[index]"
                                :data-testid="`game-id_kind-${index}`"
                                :aria-label="`Player ID kind ${index + 1}`"
                                :placeholder="index ? 'Epic ID' : 'Steam ID'"
                                autocomplete="off"
                                :aria-invalid="!!kindError"
                            />
                            <Button
                                v-if="form.id_kinds.length > 1"
                                type="button"
                                variant="outline"
                                size="sm"
                                :data-testid="`remove-kind-${index}`"
                                :aria-label="`Remove ${kind || `kind ${index + 1}`}`"
                                @click="removeKind(index)"
                            >
                                Remove
                            </Button>
                        </div>
                        <div>
                            <Button type="button" variant="outline" size="sm" data-testid="add-kind" @click="addKind">Add a kind</Button>
                        </div>
                        <p class="text-xs text-muted-foreground">A game with one kind asks only for its ID; with several, leaders pick the kind first. The first is the default.</p>
                        <p v-if="kindError" class="text-xs text-destructive" data-testid="error-id_kinds">{{ kindError }}</p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="game-interface">Attendance read by</Label>
                        <NativeSelect id="game-interface" v-model="form.interface" data-testid="game-interface" :aria-invalid="!!form.errors.interface">
                            <option v-for="option in interfaces" :key="option.value" :value="option.value">{{ option.label }}</option>
                        </NativeSelect>
                        <p class="text-xs text-muted-foreground">None means reading is not set up yet. None (impossible) means this game's attendance can never be read automatically, so reports say it is self-reported. Either way leaders mark it by hand.</p>
                        <p v-if="form.errors.interface" class="text-xs text-destructive" data-testid="error-interface">{{ form.errors.interface }}</p>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="game-event-tags">Calendar tags</Label>
                        <Input
                            id="game-event-tags"
                            v-model="form.event_tags"
                            data-testid="game-event-tags"
                            placeholder="[HLL], SQUAD |"
                            autocomplete="off"
                            aria-describedby="game-event-tags-help"
                            :aria-invalid="!!tagError"
                        />
                        <p id="game-event-tags-help" class="text-xs text-muted-foreground">
                            Text in a 7Cav calendar event's title that marks it as this game's, separated by commas. Case is ignored. A section that plays the game lists its events on the roll call.
                        </p>
                        <p v-if="tagError" class="text-xs text-destructive" data-testid="error-event_tags">{{ tagError }}</p>
                    </div>

                    <fieldset class="grid gap-3" aria-describedby="game-event-calendars-help">
                        <legend class="text-sm font-medium">Forum calendars</legend>
                        <p id="game-event-calendars-help" class="text-xs text-muted-foreground">
                            The 7Cav calendars this game's events are filed in. Paste each calendar's link, like https://7cav.us/calendar/46/, separated by commas. An event in one of them is the game's whatever its title says; the calendar tags only catch events filed elsewhere.
                        </p>
                        <div v-for="kind in CALENDAR_KINDS" :key="kind.value" class="grid gap-1.5">
                            <Label :for="`game-calendars-${kind.value}`">{{ kind.label }}</Label>
                            <Input
                                :id="`game-calendars-${kind.value}`"
                                v-model="form.event_calendars[kind.value]"
                                :data-testid="`game-calendars-${kind.value}`"
                                placeholder="https://7cav.us/calendar/46/"
                                autocomplete="off"
                                :aria-invalid="!!calendarError(kind.value)"
                            />
                            <p v-if="calendarError(kind.value)" class="text-xs text-destructive" :data-testid="`error-event_calendars-${kind.value}`">{{ calendarError(kind.value) }}</p>
                        </div>
                    </fieldset>

                    <div class="flex gap-2">
                        <Button type="submit" data-testid="submit-game" :disabled="form.processing">{{ editing ? 'Save changes' : 'Add game' }}</Button>
                        <Button v-if="editing" type="button" variant="outline" data-testid="cancel-edit" @click="cancelEdit">Cancel</Button>
                    </div>
                </form>
            </Card>
        </div>

        <Card v-if="isAdmin && identitiesToCheck.length" class="min-w-0 gap-0 py-0" data-testid="identities-to-check">
            <div class="px-4 pt-3.5 pb-2">
                <h2 class="text-[15px] font-semibold">Identities to check</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Saved before these checks existed. Nothing here was changed. Fix each one on Game identities.
                </p>
            </div>
            <Table class="text-[13px]">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">Trooper</TableHead>
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">Game</TableHead>
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">ID</TableHead>
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">What to check</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="row in identitiesToCheck" :key="row.id" :data-testid="`check-${row.id}`">
                        <TableCell class="px-4 align-top font-medium">{{ row.trooper ?? 'Unknown trooper' }}</TableCell>
                        <TableCell class="align-top">{{ row.game }}<span class="block text-xs text-muted-foreground">{{ row.idKind }}</span></TableCell>
                        <TableCell class="align-top whitespace-normal">
                            <bdi class="block font-mono text-xs break-all">{{ row.platformId }}</bdi>
                        </TableCell>
                        <TableCell class="px-4 align-top whitespace-normal">
                            <ul class="grid gap-0.5 text-xs">
                                <li v-for="problem in row.problems" :key="problem">{{ problem }}</li>
                            </ul>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </Card>
    </div>
</template>
