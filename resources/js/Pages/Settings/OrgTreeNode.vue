<script setup>
import { computed } from 'vue';
import { PhArrowBendDownRight, PhCaretDown, PhCaretRight, PhPencilSimple, PhPlus, PhTrash } from '@phosphor-icons/vue';
import Guidon from '@/components/Guidon.vue';

defineOptions({ name: 'OrgTreeNode' });

const props = defineProps({
    node: { type: Object, required: true },
    selectedId: { type: Number, default: null },
    // The unit just picked from the search box: its row glows briefly so the eye lands on it.
    highlightId: { type: Number, default: null },
    collapsed: { type: Object, required: true }, // reactive Set of collapsed unit ids
    isAdmin: { type: Boolean, default: false },
    // Width (in ch) the name takes so the callsigns of sister units start in one column; null leaves it natural.
    nameCh: { type: Number, default: null },
});

const emit = defineEmits(['select', 'toggle', 'addChild', 'delete', 'reference']);

// Mirrors `settings-v1-tree.html`'s per-row `.tools`: the root (no parent) only ever gets
// rename, since it can't be re-homed or removed and a sibling regiment isn't this story's
// scope; a leaf (Section) can't have children so it gets no add-child tool.
const ECHELON_ORDER = ['regiment', 'battalion', 'company', 'platoon', 'section'];
const isRoot = computed(() => props.node.parent_id === null);
// A group (staff department, ELOA, Reservists, New Recruits) gets no add-child tool: groups are the import's.
const childType = computed(() => (props.node.type === 'group' ? null : (ECHELON_ORDER[ECHELON_ORDER.indexOf(props.node.type) + 1] ?? null)));
// Who oversees the unit (a battalion or the Reservists), or vacant for a battalion with nobody serving in it.
// An HQ sits among its unit's children but is not a sister unit, so it neither sets nor takes the shared width.
const isHq = (node) => /HQ|headquarters/i.test(node.name ?? '');
const childNameCh = computed(() => {
    const names = props.node.children.filter((child) => !child.ref && child.callsign && !isHq(child)).map((child) => child.name.length);
    return names.length > 1 ? Math.max(...names) : null;
});
// Only from `sm` up (the class reads the variable): on a phone the callsign sits under the name instead.
const nameStyle = computed(() => (props.nameCh && props.node.callsign && !isHq(props.node) ? { '--name-ch': `${props.nameCh}ch` } : undefined));

// The game chip: only on a row below the regiment that has a game of its own and no unit above with one, as the game's
// short code. Units under it show no game; the details panel says which one applies.
const gameChip = computed(() => {
    const { node } = props;
    if (!node.game || !node.sets_game || node.type === 'regiment') return null;
    const name = `${node.game.name}${node.game.retired ? ' (retired)' : ''}`;
    return { text: node.game.abbreviation ?? node.game.name, title: node.game.from ? `${name}, from ${node.game.from}` : name };
});

// Game, vacant lead and oversight: inline from `sm` up, their own line under the name on a phone.
const hasMeta = computed(() => Boolean(gameChip.value || (props.node.lead_vacant && !props.node.empty) || oversightText.value));

const oversightText = computed(() => {
    if (props.node.oversight) return `Oversight: ${props.node.oversight.displayName}${props.node.oversight.serving ? '' : ' (not serving)'}`;
    return props.node.oversight_vacant ? 'Oversight: vacant' : null;
});
</script>

<template>
    <!--
      A reference row: a unit shown here as well as at its own place (ACD under RDC, DEVCOM's staff under DEVCOM). It has
      no branch and no tools; choosing it opens the unit where it really sits.
    -->
    <li v-if="node.ref" role="none">
        <div
            :data-testid="`unit-ref-${node.refParentId}-${node.id}`"
            role="treeitem"
            tabindex="0"
            :aria-selected="false"
            class="flex min-h-8 cursor-pointer flex-wrap items-center gap-x-1.5 gap-y-0.5 rounded-md px-1.5 py-1 text-[13px] text-muted-foreground hover:bg-accent/70"
            @click="emit('reference', node.id)"
            @keydown.enter.space.prevent="emit('reference', node.id)"
        >
            <PhArrowBendDownRight class="size-3.5 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1 break-words sm:flex-initial">
                {{ node.name }}
                <span v-if="node.callsign" class="ml-1.5 font-mono text-[11px]">{{ node.callsign }}</span>
                <span class="ml-1.5 text-[11px]">{{ node.refNote }}</span>
            </span>
            <span
                v-if="gameChip"
                :data-testid="`ref-game-${node.refParentId}-${node.id}`"
                class="shrink-0 rounded-sm border border-border px-1 font-mono text-[10px] font-semibold tracking-wide"
                :title="gameChip.title"
                >{{ gameChip.text }}</span
            >
            <span v-if="oversightText" :data-testid="`ref-oversight-${node.refParentId}-${node.id}`" class="text-[11px] max-sm:order-last max-sm:basis-full max-sm:pl-5 sm:shrink-0">{{ oversightText }}</span>
            <span class="ml-auto shrink-0 text-[11px] tabular-nums"><span class="sr-only sm:not-sr-only">Headcount </span>{{ node.trooper_count }}</span>
        </div>
    </li>
    <li v-else role="none">
        <div
            :data-testid="`unit-row-${node.id}`"
            role="treeitem"
            tabindex="0"
            :aria-selected="node.id === selectedId"
            :aria-expanded="node.children.length ? !collapsed.has(node.id) : undefined"
            :data-highlighted="node.id === highlightId || undefined"
            class="group relative flex min-h-8 cursor-pointer flex-wrap items-center gap-x-1.5 gap-y-0.5 rounded-md px-1.5 py-1 text-[13px] transition-shadow duration-700 hover:bg-accent/70"
            :class="{
                'text-muted-foreground': node.empty,
                'bg-accent font-medium shadow-[inset_2px_0_0_var(--primary)]': node.id === selectedId,
                'ring-2 ring-primary ring-inset duration-150': node.id === highlightId,
            }"
            @click="emit('select', node.id)"
            @keydown.enter.space.prevent="emit('select', node.id)"
        >
            <button
                v-if="node.children.length"
                type="button"
                :aria-expanded="!collapsed.has(node.id)"
                :aria-label="collapsed.has(node.id) ? `Expand ${node.name}` : `Collapse ${node.name}`"
                class="grid size-5 place-items-center rounded-sm text-muted-foreground hover:bg-accent hover:text-foreground"
                @click.stop="emit('toggle', node.id)"
            >
                <PhCaretRight v-if="collapsed.has(node.id)" class="size-3.5" />
                <PhCaretDown v-else class="size-3.5" />
            </button>
            <span v-else class="size-5" aria-hidden="true"></span>

            <!-- The unit's saved guidon (#446); nothing for a unit with none. -->
            <Guidon :design="node.guidon" :height="12" />
            <!-- On a phone the name takes the row and the callsign sits under it; from `sm` up they share a line, with
                 sister units' callsigns lined up in one column. -->
            <span class="flex min-w-0 flex-1 flex-col sm:flex-initial sm:flex-row sm:items-baseline">
                <span class="break-words sm:shrink-0 sm:min-w-(--name-ch)" :style="nameStyle">{{ node.name }}</span>
                <span v-if="node.callsign" class="font-mono text-[11px] break-all text-muted-foreground sm:ml-1.5">{{ node.callsign }}</span>
            </span>
            <span v-if="hasMeta" class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 max-sm:order-last max-sm:basis-full max-sm:pl-[1.625rem] sm:ml-1.5">
            <!-- The game chip, on the unit that set it: the game's short code, its full name on hover. -->
            <span
                v-if="gameChip"
                :data-testid="`unit-game-${node.id}`"
                class="shrink-0 rounded-sm border border-border bg-muted/50 px-1 font-mono text-[10px] font-semibold tracking-wide text-foreground/80"
                :title="gameChip.title"
                :aria-label="`Game: ${gameChip.title}`"
                >{{ gameChip.text }}</span
            >
            <!--
              A billet commands this unit and nobody serving holds it. No previous holder is remembered. Not said of an
              empty unit, which is shown muted only when asked for.
            -->
            <span v-if="node.lead_vacant && !node.empty" :data-testid="`lead-vacant-${node.id}`" class="shrink-0 text-[11px] text-warning">Lead: vacant</span>
            <span
                v-if="oversightText"
                :data-testid="`oversight-${node.id}`"
                class="shrink-0 text-[11px]"
                :class="node.oversight?.serving ? 'text-muted-foreground' : 'text-warning'"
                >{{ oversightText }}</span
            >
            </span>

            <!-- Just the number on a phone; the word stays for screen readers. -->
            <span class="ml-auto shrink-0 text-[11px] text-muted-foreground tabular-nums"><span class="sr-only sm:not-sr-only">Headcount </span>{{ node.trooper_count }}</span>

            <!--
              Inline tools (UX-DR4 / settings-v1-tree.html), revealed on hover or focus. Rename and
              delete are Admin-only; add-child shows wherever the viewer may add (`can_add_child`). The
              server enforces the same gates regardless (AD-6). Root gets rename only; a leaf gets no add-child.
            -->
            <span
                v-if="isAdmin || node.can_add_child"
                class="shrink-0 items-center gap-0.5 sm:flex sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100"
                :class="node.id === selectedId ? 'flex' : 'hidden'"
            >
                <button
                    v-if="isAdmin"
                    type="button"
                    :data-testid="`tool-edit-${node.id}`"
                    :aria-label="`Rename ${node.name}`"
                    :title="`Rename ${node.name}`"
                    class="grid size-6 place-items-center rounded-sm text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click.stop="emit('select', node.id)"
                >
                    <PhPencilSimple class="size-3.5" />
                </button>
                <button
                    v-if="childType && !isRoot && (isAdmin || node.can_add_child)"
                    type="button"
                    :data-testid="`tool-add-${node.id}`"
                    :aria-label="`Add ${childType} under ${node.name}`"
                    :title="`Add ${childType}`"
                    class="grid size-6 place-items-center rounded-sm text-muted-foreground hover:bg-accent hover:text-foreground"
                    @click.stop="emit('addChild', { parentId: node.id, type: childType })"
                >
                    <PhPlus class="size-3.5" />
                </button>
                <button
                    v-if="isAdmin && !isRoot"
                    type="button"
                    :data-testid="`tool-delete-${node.id}`"
                    :aria-label="`Delete ${node.name}`"
                    :title="`Delete ${node.name}`"
                    class="grid size-6 place-items-center rounded-sm text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                    @click.stop="emit('delete', node.id)"
                >
                    <PhTrash class="size-3.5" />
                </button>
            </span>
        </div>

        <ul v-if="node.children.length && !collapsed.has(node.id)" role="group" class="ml-[1.1rem] border-l border-border pl-1.5">
            <OrgTreeNode
                v-for="child in node.children"
                :key="child.id"
                :node="child"
                :selected-id="selectedId"
                :highlight-id="highlightId"
                :collapsed="collapsed"
                :is-admin="isAdmin"
                :name-ch="childNameCh"
                @select="(id) => emit('select', id)"
                @toggle="(id) => emit('toggle', id)"
                @add-child="(payload) => emit('addChild', payload)"
                @delete="(id) => emit('delete', id)"
                @reference="(id) => emit('reference', id)"
            />
        </ul>
    </li>
</template>
