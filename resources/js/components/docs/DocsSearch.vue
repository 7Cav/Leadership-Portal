<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ListboxFilter } from 'reka-ui';
import { PhBookOpenText, PhMagnifyingGlass } from '@phosphor-icons/vue';
import { Command, CommandGroup, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { useDocsSearch } from '@/composables/useDocsSearch';
import { searchGuides } from '@/lib/docsSearch';

// The docs search dialog (#413), after the trooper search's: type, move with the arrow keys, open a guide with Enter
// or a click. The index is the page's, already limited to the guides this reader may read (view as included), so the
// search runs here with no request. The input is a bare ListboxFilter, not CommandInput, which would filter the rows a
// second time by their visible text.
const props = defineProps({
    // `[{ title, summary, url, section, headings, text }]`
    index: { type: Array, required: true },
});

const { searchOpen } = useDocsSearch();
const query = ref('');
const results = computed(() => searchGuides(props.index, query.value));

// "What others can do" is the place to send someone whose words found nothing, when this reader has it.
const othersGuide = computed(() => props.index.find((guide) => guide.url.endsWith('/what-others-can-do')) ?? null);

// Each opening starts blank.
watch(searchOpen, (open) => {
    if (!open) query.value = '';
});

function choose(guide) {
    searchOpen.value = false;
    router.visit(guide.url);
}
</script>

<template>
    <Dialog v-model:open="searchOpen">
        <DialogContent
            data-testid="docs-search"
            :show-close-button="false"
            class="top-[12vh] translate-y-0 gap-0 overflow-hidden rounded-lg bg-popover p-0 shadow-panel sm:max-w-[560px]"
        >
            <DialogTitle class="sr-only">Search the guides</DialogTitle>
            <DialogDescription class="sr-only">Search the guides you can read by any word in them, then open one.</DialogDescription>

            <Command class="rounded-none bg-transparent">
                <div class="flex h-12 items-center gap-2 border-b px-3">
                    <PhMagnifyingGlass class="size-4 shrink-0 opacity-50" aria-hidden="true" />
                    <ListboxFilter
                        v-model="query"
                        auto-focus
                        maxlength="100"
                        placeholder="Search, for example: weekly report, guest, link player"
                        aria-label="Search the guides"
                        autocomplete="off"
                        data-testid="docs-search-input"
                        class="flex h-10 w-full rounded-md bg-transparent py-3 text-[14px] outline-hidden placeholder:text-muted-foreground"
                    />
                </div>

                <p v-if="!query.trim()" class="px-4 py-8 text-center text-[13px] text-muted-foreground" data-testid="docs-search-hint">
                    Type a word you remember, such as guest, LOA or posted.
                </p>

                <p v-else-if="!results.length" class="px-6 py-8 text-center text-[13px] text-muted-foreground" data-testid="docs-search-empty">
                    No guide found. Try fewer words, or see
                    <Link v-if="othersGuide" :href="othersGuide.url" class="underline underline-offset-2" @click="searchOpen = false">What others can do</Link>
                    <template v-else>What others can do</template>.
                </p>

                <CommandList v-else class="max-h-[min(420px,60vh)] p-1" data-testid="docs-search-results">
                    <CommandGroup heading="Guides">
                        <CommandItem
                            v-for="guide in results"
                            :key="guide.url"
                            :value="guide.url"
                            class="gap-2.5 py-2 text-[13.5px]"
                            @select="choose(guide)"
                        >
                            <PhBookOpenText class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{ guide.title }}</span>
                                <small class="block truncate text-[12px] text-muted-foreground">{{ guide.summary }}</small>
                            </span>
                            <span class="ml-auto shrink-0 text-[12px] text-muted-foreground">{{ guide.section }}</span>
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </DialogContent>
    </Dialog>
</template>
