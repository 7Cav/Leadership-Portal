<script setup>
import { computed } from 'vue';
import { PhCaretDown, PhCaretRight, PhPencilSimple, PhPlus, PhTrash } from '@phosphor-icons/vue';

defineOptions({ name: 'OrgTreeNode' });

const props = defineProps({
    node: { type: Object, required: true },
    selectedId: { type: Number, default: null },
    // The unit just picked from the search box: its row glows briefly so the eye lands on it.
    highlightId: { type: Number, default: null },
    collapsed: { type: Object, required: true }, // reactive Set of collapsed unit ids
    isAdmin: { type: Boolean, default: false },
});

const emit = defineEmits(['select', 'toggle', 'addChild', 'delete']);

// Mirrors `settings-v1-tree.html`'s per-row `.tools`: the root (no parent) only ever gets
// rename, since it can't be re-homed or removed and a sibling regiment isn't this story's
// scope; a leaf (Section) can't have children so it gets no add-child tool.
const ECHELON_ORDER = ['regiment', 'battalion', 'company', 'platoon', 'section'];
const isRoot = computed(() => props.node.parent_id === null);
const childType = computed(() => ECHELON_ORDER[ECHELON_ORDER.indexOf(props.node.type) + 1] ?? null);
</script>

<template>
    <li role="none">
        <div
            :data-testid="`unit-row-${node.id}`"
            role="treeitem"
            tabindex="0"
            :aria-selected="node.id === selectedId"
            :aria-expanded="node.children.length ? !collapsed.has(node.id) : undefined"
            :data-highlighted="node.id === highlightId || undefined"
            class="group relative flex min-h-8 cursor-pointer items-center gap-1.5 rounded-md px-1.5 py-1 text-[13px] transition-shadow duration-700 hover:bg-accent/70"
            :class="{
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

            <span class="min-w-0 truncate">
                {{ node.name }}
                <span v-if="node.callsign" class="ml-1.5 font-mono text-[11px] text-muted-foreground">{{ node.callsign }}</span>
            </span>

            <span class="ml-auto shrink-0 text-[11px] text-muted-foreground tabular-nums">{{ node.trooper_count }} {{ node.trooper_count === 1 ? 'trooper' : 'troopers' }}</span>

            <!--
              Inline tools (UX-DR4 / settings-v1-tree.html), Admin-only and revealed on hover or
              focus. Hidden here for a non-Admin per the mockup; the server enforces the same gate
              regardless (AD-6). Root gets rename only; a leaf gets no add-child.
            -->
            <span
                v-if="isAdmin"
                class="flex shrink-0 items-center gap-0.5 opacity-0 group-focus-within:opacity-100 group-hover:opacity-100"
            >
                <button
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
                    v-if="childType && !isRoot"
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
                    v-if="!isRoot"
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
                @select="(id) => emit('select', id)"
                @toggle="(id) => emit('toggle', id)"
                @add-child="(payload) => emit('addChild', payload)"
                @delete="(id) => emit('delete', id)"
            />
        </ul>
    </li>
</template>
