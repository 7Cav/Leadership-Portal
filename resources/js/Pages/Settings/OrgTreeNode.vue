<script setup>
defineOptions({ name: 'OrgTreeNode' });

const props = defineProps({
    node: { type: Object, required: true },
    selectedId: { type: Number, default: null },
    collapsed: { type: Object, required: true }, // reactive Set of collapsed unit ids
});

const emit = defineEmits(['select', 'toggle']);
</script>

<template>
    <li role="none">
        <div
            :data-testid="`unit-row-${node.id}`"
            role="treeitem"
            tabindex="0"
            :aria-selected="node.id === selectedId"
            class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] hover:bg-panel-2 focus-visible:outline focus-visible:outline-gold-soft"
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
        </div>

        <ul v-if="node.children.length && !collapsed.has(node.id)" role="group" class="ml-4 border-l border-border pl-2">
            <OrgTreeNode
                v-for="child in node.children"
                :key="child.id"
                :node="child"
                :selected-id="selectedId"
                :collapsed="collapsed"
                @select="(id) => emit('select', id)"
                @toggle="(id) => emit('toggle', id)"
            />
        </ul>
    </li>
</template>
