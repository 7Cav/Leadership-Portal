<script setup>
import { PhMagnifyingGlass } from '@phosphor-icons/vue';
import { useDocsSearch } from '@/composables/useDocsSearch';
import { cn } from '@/lib/utils';

// What looks like a search box on the docs pages (#413): pressing it opens the search dialog, where the typing
// happens. A button, so it never takes text it would have to hand over.
const props = defineProps({
    placeholder: { type: String, default: 'Search the guides' },
    class: { type: [String, Array, Object], default: '' },
});

const { openSearch } = useDocsSearch();
</script>

<template>
    <button
        type="button"
        aria-haspopup="dialog"
        aria-keyshortcuts="Control+K Meta+K"
        data-testid="docs-search-trigger"
        :class="
            cn(
                'flex h-9 w-full items-center gap-2.5 rounded-md border border-input bg-card px-3 text-left text-[13px] text-muted-foreground hover:border-ring/60',
                props.class,
            )
        "
        @click="openSearch"
    >
        <PhMagnifyingGlass class="size-4 shrink-0" aria-hidden="true" />
        <span class="min-w-0 flex-1 truncate">{{ placeholder }}</span>
        <kbd class="hidden rounded-sm border border-border bg-muted px-1 font-mono text-[11px] leading-4 sm:inline" aria-hidden="true">Ctrl K</kbd>
    </button>
</template>
