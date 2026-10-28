<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { ListboxFilter } from 'reka-ui';
import { PhMagnifyingGlass, PhSignOut, PhWarningCircle } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Command, CommandGroup, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import RankBadge from '@/components/roster/RankBadge.vue';
import { DEBOUNCE_MS, MAX_CHARS, MIN_CHARS, useTrooperSearch } from '@/composables/useTrooperSearch';
import { reloadPage, sessionEnded } from '@/lib/postJson';

// The shell's trooper search: type part of a username (a leading rank and a space for the dot are fine, and a number
// finds that milpac id), pick a trooper, land on their milpac. The server answers with only
// the troopers in the viewer's scope (the milpac's own rule), so nothing here decides who may be seen. The list is
// the server's, not filtered again here, which is why the input is a bare ListboxFilter and not CommandInput (that
// one would also filter the rows by their text).
const { searchOpen } = useTrooperSearch();

const query = ref('');
const status = ref('idle'); // idle | loading | ready | error | throttled | tooLong
const results = ref([]);
const more = ref(false);
// Whether the server limited the answer to the viewer's units (anyone but an acting Admin), so an empty list can say so.
const scoped = ref(true);

let timer = null;
let controller = null;
let latest = 0;

// Characters as the server counts them (`mb_strlen`, one per code point), not as `String.length` does (UTF-16 units,
// two for an emoji), so the dialog sends exactly the searches the server accepts.
const charCount = (text) => [...text].length;

function clearResults(next) {
    results.value = [];
    more.value = false;
    status.value = next;
}

function cancel() {
    clearTimeout(timer);
    controller?.abort();
    controller = null;
}

async function run(q) {
    const id = ++latest;
    controller = new AbortController();
    try {
        const response = await fetch(`/troopers/search?q=${encodeURIComponent(q)}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
        });
        if (id !== latest) return;
        // A session that ended while the page sat open: reload into sign-in, as the other JSON fetches do.
        if (sessionEnded(response.status)) {
            reloadPage();
            return;
        }
        if (response.status === 429) {
            status.value = 'throttled';
            return;
        }
        // The server's length check (2 to 64 characters) is not a broken connection. The dialog only sends a search it
        // counts in range, so a refusal means the server counted more, as for a space PHP's trim keeps: say too long.
        if (response.status === 422) {
            clearResults(charCount(q) < MIN_CHARS ? 'idle' : 'tooLong');
            return;
        }
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const body = await response.json();
        if (id !== latest) return;
        results.value = body.troopers;
        more.value = body.more;
        scoped.value = body.scoped !== false;
        status.value = 'ready';
    } catch (error) {
        if (error?.name === 'AbortError' || id !== latest) return;
        status.value = 'error';
    }
}

// Waits for a pause in typing, and drops an answer that a newer search has overtaken.
watch(query, (value) => {
    cancel();
    const q = value.trim();
    const length = charCount(q);
    if (length < MIN_CHARS || length > MAX_CHARS) {
        latest++;
        clearResults(length > MAX_CHARS ? 'tooLong' : 'idle');
        return;
    }
    status.value = 'loading';
    timer = setTimeout(() => run(q), DEBOUNCE_MS);
});

// Try again shows the search under way, and drops a request still out (or a search waiting on the debounce) first, so
// only this one's answer can land.
function retry() {
    cancel();
    status.value = 'loading';
    run(query.value.trim());
}

// Each opening starts blank: an old search would show troopers from a scope the viewer may since have left.
watch(searchOpen, (open) => {
    if (open) return;
    cancel();
    query.value = '';
});

onBeforeUnmount(cancel);

function choose(trooper) {
    searchOpen.value = false;
    router.visit(`/troopers/${trooper.id}`);
}
</script>

<template>
    <Dialog v-model:open="searchOpen">
        <DialogContent
            data-testid="trooper-search"
            :show-close-button="false"
            class="top-[12vh] translate-y-0 gap-0 overflow-hidden rounded-lg bg-popover p-0 shadow-panel sm:max-w-[560px]"
        >
            <DialogTitle class="sr-only">Find a trooper</DialogTitle>
            <DialogDescription class="sr-only">Search the troopers in your units by username, then open their page.</DialogDescription>

            <Command class="rounded-none bg-transparent">
                <div class="flex h-12 items-center gap-2 border-b px-3">
                    <PhMagnifyingGlass class="size-4 shrink-0 opacity-50" aria-hidden="true" />
                    <ListboxFilter
                        v-model="query"
                        auto-focus
                        :maxlength="MAX_CHARS"
                        placeholder="Find a trooper by username, such as SGT Vasquez.R"
                        aria-label="Search troopers by username"
                        autocomplete="off"
                        spellcheck="false"
                        data-testid="trooper-search-input"
                        class="flex h-10 w-full rounded-md bg-transparent py-3 text-[14px] outline-hidden placeholder:text-muted-foreground"
                    />
                </div>

                <p v-if="status === 'idle'" class="px-4 py-8 text-center text-[13px] text-muted-foreground" data-testid="trooper-search-hint">
                    Type at least {{ MIN_CHARS }} letters of a username.
                </p>

                <div v-else-if="status === 'loading'" class="space-y-1 p-2" aria-busy="true" data-testid="trooper-search-loading">
                    <span class="sr-only">Searching</span>
                    <div v-for="row in 4" :key="row" class="flex items-center gap-3 px-2 py-2">
                        <Skeleton class="h-[1.375rem] w-12" />
                        <Skeleton class="h-3.5 w-28" />
                        <Skeleton class="ml-auto h-3.5 w-20" />
                    </div>
                </div>

                <div v-else-if="status === 'error'" role="alert" class="flex flex-col items-center gap-3 px-6 py-8 text-center" data-testid="trooper-search-error">
                    <PhWarningCircle class="size-6 text-destructive" aria-hidden="true" />
                    <p class="max-w-[42ch] text-[13px] text-muted-foreground">The search did not load. Check your connection, then try again.</p>
                    <Button variant="outline" size="sm" @click="retry">Try again</Button>
                </div>

                <p v-else-if="status === 'tooLong'" class="px-6 py-8 text-center text-[13px] text-muted-foreground" data-testid="trooper-search-too-long">
                    That is longer than any name. Search with at most {{ MAX_CHARS }} characters.
                </p>

                <p v-else-if="status === 'throttled'" role="alert" class="px-6 py-8 text-center text-[13px] text-muted-foreground" data-testid="trooper-search-throttled">
                    Too many searches in a short time. Wait a moment, then keep typing.
                </p>

                <div v-else-if="!results.length" class="px-6 py-8 text-center text-[13px] text-muted-foreground" data-testid="trooper-search-empty">
                    <p>No trooper in your scope matches "{{ query.trim() }}".</p>
                    <p v-if="scoped" class="mx-auto mt-1 max-w-[46ch] text-[12px]" data-testid="trooper-search-scope-note">
                        Only troopers in your units are shown.
                    </p>
                </div>

                <CommandList v-else class="max-h-[min(420px,60vh)] p-1" data-testid="trooper-search-results">
                    <CommandGroup heading="Troopers">
                        <CommandItem
                            v-for="trooper in results"
                            :key="trooper.id"
                            :value="trooper.id"
                            :data-testid="`trooper-search-result-${trooper.id}`"
                            class="gap-2.5 py-2 text-[13.5px]"
                            @select="choose(trooper)"
                        >
                            <RankBadge :rank="trooper.rank" :title="trooper.rankFull" />
                            <span class="min-w-0 truncate font-medium" :class="trooper.departed && 'text-muted-foreground'">{{ trooper.name }}</span>
                            <Badge v-if="trooper.departed" variant="outline" class="shrink-0 text-[11px]"><PhSignOut />Departed</Badge>
                            <span class="ml-auto shrink-0 font-mono text-[12px] text-muted-foreground">{{ trooper.section?.designation ?? 'Not placed' }}</span>
                        </CommandItem>
                    </CommandGroup>
                </CommandList>

                <p v-if="status === 'ready' && more" class="border-t px-4 py-2 text-[12px] text-muted-foreground" data-testid="trooper-search-more">
                    Showing the first {{ results.length }}. Type more of the name to narrow it down.
                </p>
            </Command>
        </DialogContent>
    </Dialog>
</template>
