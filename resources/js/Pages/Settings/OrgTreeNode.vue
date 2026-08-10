<script setup>
import { computed } from 'vue';

defineOptions({ name: 'OrgTreeNode' });

const props = defineProps({
    node: { type: Object, required: true },
    selectedId: { type: Number, default: null },
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
            class="group flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] hover:bg-panel-2 focus-visible:outline focus-visible:outline-gold-soft"
            :class="{ 'border border-gold-soft bg-panel-2': node.id === selectedId }"
            @click="emit('select', node.id)"
            @keydown.enter.space.prevent="emit('select', node.id)"
        >
            <button
                v-if="node.children.length"
                type="button"
                :aria-expanded="!collapsed.has(node.id)"
                :aria-label="collapsed.has(node.id) ? `Expand ${node.name}` : `Collapse ${node.name}`"
                class="w-3 text-[11px] text-gray-500"
                @click.stop="emit('toggle', node.id)"
            >
                {{ collapsed.has(node.id) ? '▸' : '▾' }}
            </button>
            <span v-else class="w-3" aria-hidden="true"></span>

            <span class="font-medium">
                {{ node.name }}
                <span v-if="node.callsign" class="ml-1.5 font-mono text-[11px] text-gray-500">{{ node.callsign }}</span>
            </span>

            <span class="ml-auto font-mono text-[11px] text-gray-500">{{ node.trooper_count }} troopers</span>

            <!--
              Inline tools (UX-DR4 / settings-v1-tree.html), Admin-only and hover-revealed —
              hidden here for a non-Admin per the mockup, the server enforces the same gate
              regardless (AD-6). Root gets rename only; a leaf gets no add-child.
            -->
            <span v-if="isAdmin" class="ml-2 flex items-center gap-1 opacity-0 group-hover:opacity-100">
                <button
                    type="button"
                    :data-testid="`tool-edit-${node.id}`"
                    title="Rename"
                    class="text-[11px] text-gray-400 hover:text-gold"
                    @click.stop="emit('select', node.id)"
                >
                    ✎
                </button>
                <button
                    v-if="childType"
                    type="button"
                    :data-testid="`tool-add-${node.id}`"
                    :title="`Add ${childType}`"
                    class="text-[11px] text-gray-400 hover:text-gold"
                    @click.stop="emit('addChild', { parentId: node.id, type: childType })"
                >
                    ＋
                </button>
                <button
                    v-if="!isRoot"
                    type="button"
                    :data-testid="`tool-delete-${node.id}`"
                    title="Delete"
                    class="text-[11px] text-gray-400 hover:text-bad"
                    @click.stop="emit('delete', node.id)"
                >
                    🗑
                </button>
            </span>
        </div>

        <ul v-if="node.children.length && !collapsed.has(node.id)" role="group" class="ml-4 border-l border-border pl-2">
            <OrgTreeNode
                v-for="child in node.children"
                :key="child.id"
                :node="child"
                :selected-id="selectedId"
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
