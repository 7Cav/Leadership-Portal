<script setup>
import { computed } from 'vue';
import SearchSelect from '@/components/SearchSelect.vue';

// The "User" filter on the Audit log and the Activity log: everyone the log names, alphabetical, in a SearchSelect.
// The whole list is already on the page (the server sends only the people who appear in that log), so the search
// runs here. `v-model` is the chosen user's id, or null for anyone.
const props = defineProps({
    users: { type: Array, required: true },
    id: { type: String, default: undefined },
    testid: { type: String, default: undefined },
});

const model = defineModel({ type: Number, default: null });

const options = computed(() =>
    [...props.users]
        .sort((a, b) => a.username.localeCompare(b.username, undefined, { sensitivity: 'base' }))
        .map((user) => ({ value: user.id, label: user.username })),
);
</script>

<template>
    <SearchSelect
        :id="id"
        v-model="model"
        :options="options"
        any-label="Any user"
        search-placeholder="Search users"
        empty-text="No user matches that search."
        :testid="testid"
        content-testid="user-filter-popover"
    />
</template>
