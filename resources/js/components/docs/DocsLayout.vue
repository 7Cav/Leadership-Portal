<script setup>
import { ref } from 'vue';
import { PhCaretDown, PhListBullets } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { useDocsSearchShortcut } from '@/composables/useDocsSearch';
import DocsSearch from './DocsSearch.vue';
import DocsSearchBox from './DocsSearchBox.vue';
import DocsTree from './DocsTree.vue';

// The three columns of every docs page (#410, agreed design): contents on the left, the page in the middle, and a
// right rail (Start here, and "On this page" on a guide). On a narrow screen the page comes first: the contents sit
// behind a "Contents" button above it and the rail drops below it.
defineProps({
    contents: { type: Array, required: true },
    current: { type: String, default: null },
    // The page's search index (#413), only the guides this reader may read.
    search: { type: Array, required: true },
});

useDocsSearchShortcut();

const contentsOpen = ref(false);
</script>

<template>
    <div
        class="grid grid-cols-1 items-start gap-6 lg:grid-cols-[240px_minmax(0,1fr)] lg:gap-9 xl:grid-cols-[260px_minmax(0,1fr)_280px]"
    >
        <div
            class="[scrollbar-color:var(--border)_transparent] [scrollbar-width:thin] lg:sticky lg:top-[calc(var(--shell-top,3rem)+1.25rem)] lg:row-span-2 lg:max-h-[calc(100dvh-var(--shell-top,3rem)-2.5rem)] lg:overflow-auto lg:pr-1"
            data-testid="docs-contents-column"
        >
            <Button
                variant="outline"
                class="w-full justify-start lg:hidden"
                data-testid="docs-contents-toggle"
                :aria-expanded="contentsOpen"
                aria-controls="docs-contents"
                @click="contentsOpen = !contentsOpen"
            >
                <PhListBullets />
                Contents
                <PhCaretDown class="ml-auto transition-transform" :class="contentsOpen ? 'rotate-180' : ''" />
            </Button>
            <div id="docs-contents" :class="contentsOpen ? 'mt-3' : 'hidden lg:block'">
                <DocsTree :contents="contents" :current="current">
                    <template #top><DocsSearchBox class="mb-3.5" /></template>
                </DocsTree>
            </div>
        </div>

        <div class="min-w-0">
            <slot />
        </div>

        <aside
            v-if="$slots.rail"
            class="flex min-w-0 flex-col gap-5 lg:col-start-2 xl:sticky xl:top-[calc(var(--shell-top,3rem)+1.25rem)] xl:col-start-3 xl:row-start-1"
            aria-label="Guide tools"
        >
            <slot name="rail" />
        </aside>
    </div>

    <DocsSearch :index="search" />
</template>
