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

// The games the regiment plays (#388): the list on the left, and the add/edit form alongside for an Admin (the
// Staff grants layout). A game in use can be retired, not deleted, so Delete is offered only for one nothing uses.
// An Admin also sees the identities saved before #450 that break its rules, to fix on Game identities.
const props = defineProps({
    // `[{ id, slug, name, idKinds, checkedKinds, interface, retired, inUse }]`, in the order they were added;
    // `checkedKinds` are the kinds whose IDs the server checks for a shape.
    games: { type: Array, required: true },
    // `[{ value, label }]`: the telemetry interfaces the portal has built in.
    interfaces: { type: Array, required: true },
    isAdmin: { type: Boolean, required: true },
    // Admin only: `[{ id, trooper, game, idKind, playerName, platformId, problems }]`, hidden characters written out.
    identitiesToCheck: { type: Array, default: () => [] },
});

const editingId = ref(null);
const editing = computed(() => props.games.find((game) => game.id === editingId.value) ?? null);
const rowError = ref(null);

// `id_kinds` is the list of player-ID kinds the game asks for ("Steam ID", "Epic ID"), the first being the default.
const form = useForm({ name: '', id_kinds: [''], interface: 'none' });

const interfaceLabel = (value) => props.interfaces.find((option) => option.value === value)?.label ?? value;

function edit(game) {
    editingId.value = game.id;
    form.clearErrors();
    form.name = game.name;
    form.id_kinds = [...game.idKinds];
    form.interface = game.interface;
}

const addKind = () => form.id_kinds.push('');
const removeKind = (index) => form.id_kinds.splice(index, 1);

// The server names a kind error by its row (`id_kinds.1`) or for the whole list (`id_kinds`, e.g. a kind in use).
const kindError = computed(() => Object.entries(form.errors).find(([key]) => key === 'id_kinds' || key.startsWith('id_kinds.'))?.[1]);

function cancelEdit() {
    editingId.value = null;
    form.clearErrors();
    form.reset();
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => cancelEdit(),
        onHttpException: onHttpException(editing.value ? 'The game was not saved' : 'The game was not added', (message) => toast.error(message)),
    };

    if (editing.value) form.patch(`/settings/games/${editing.value.id}`, options);
    else form.post('/settings/games', options);
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
                            <TableCell class="px-4 font-medium">
                                <span class="flex flex-wrap items-center gap-1.5">
                                    {{ game.name }}
                                    <Badge v-if="game.retired" variant="outline" :data-testid="`retired-${game.slug}`">Retired</Badge>
                                </span>
                            </TableCell>
                            <TableCell>
                                <span :data-testid="`kinds-${game.slug}`">{{ game.idKinds.join(', ') }}</span>
                                <span v-if="game.checkedKinds?.length" class="block text-xs text-muted-foreground" :data-testid="`checked-kinds-${game.slug}`">
                                    Shape checked: {{ game.checkedKinds.join(', ') }}
                                </span>
                            </TableCell>
                            <TableCell :data-testid="`interface-${game.slug}`">{{ interfaceLabel(game.interface) }}</TableCell>
                            <TableCell class="px-4 text-right whitespace-nowrap">
                                <template v-if="isAdmin">
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
                                </template>
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
                        <p class="text-xs text-muted-foreground">None means leaders mark this game's attendance by hand.</p>
                        <p v-if="form.errors.interface" class="text-xs text-destructive" data-testid="error-interface">{{ form.errors.interface }}</p>
                    </div>

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
                        <TableHead class="h-8 text-xs font-medium text-muted-foreground">Player name and ID</TableHead>
                        <TableHead class="h-8 px-4 text-xs font-medium text-muted-foreground">What to check</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="row in identitiesToCheck" :key="row.id" :data-testid="`check-${row.id}`">
                        <TableCell class="px-4 align-top font-medium">{{ row.trooper ?? 'Unknown trooper' }}</TableCell>
                        <TableCell class="align-top">{{ row.game }}<span class="block text-xs text-muted-foreground">{{ row.idKind }}</span></TableCell>
                        <TableCell class="align-top whitespace-normal">
                            <bdi class="block break-words">{{ row.playerName }}</bdi>
                            <bdi class="block font-mono text-xs break-all text-muted-foreground">{{ row.platformId }}</bdi>
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
