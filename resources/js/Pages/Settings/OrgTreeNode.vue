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
    <li>
        <div
            :data-testid="`unit-row-${node.id}`"
            class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] hover:bg-panel-2"
            :class="{ 'border border-gold-soft bg-panel-2': node.id === selectedId }"
            @click="emit('select', node.id)"
        >
            <span
                v-if="node.children.length"
                class="w-3 text-[11px] text-gray-500"
                @click.stop="emit('toggle', node.id)"
            >
                {{ collapsed.has(node.id) ? '▸' : '▾' }}
            </span>
            <span v-else class="w-3" aria-hidden="true"></span>

            <span class="font-medium">
                {{ node.name }}
                <span v-if="node.callsign" class="ml-1.5 font-mono text-[11px] text-gray-500">{{ node.callsign }}</span>
            </span>

            <span class="ml-auto font-mono text-[11px] text-gray-500">{{ node.trooper_count }} troopers</span>
        </div>

        <ul v-if="node.children.length && !collapsed.has(node.id)" class="ml-4 border-l border-border pl-2">
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
