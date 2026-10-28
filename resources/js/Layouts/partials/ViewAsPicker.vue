<script setup>
import { computed, ref, watch } from 'vue';
import { PhArrowCounterClockwise, PhCheck, PhUser, PhUsersThree, PhWarningCircle } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList, CommandSeparator } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useViewAs } from '@/composables/useViewAs';
import { onHttpException } from '@/lib/httpError';

// The view-as picker (Story 1.13). A search-first dialog, after the ⌘K "View as" sub-mode in
// mockups/v2/shell-d.html: units in the viewer's own scope, and for an Admin every other person.
// The list is fetched each time it opens, because real scope can change between visits; what it
// offers is a convenience only, since the server re-checks any choice against real authority.
const { viewAs, pickerOpen, enter, exit } = useViewAs();

const status = ref('idle'); // idle | loading | ready | error
const options = ref({ units: [], people: null, current: null });
const submitError = ref(null);
const pending = ref(false);

const hasPeople = computed(() => Array.isArray(options.value.people));
const nothingToOffer = computed(() => options.value.units.length === 0 && !(options.value.people?.length > 0));
const placeholder = computed(() => (hasPeople.value ? 'Find a unit or person' : 'Find a unit'));

const isCurrent = (kind, id) => options.value.current?.kind === kind && options.value.current?.id === id;

async function load() {
    status.value = 'loading';
    try {
        const response = await fetch('/view-as/options', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        options.value = await response.json();
        status.value = 'ready';
    } catch {
        status.value = 'error';
    }
}

watch(pickerOpen, (open) => {
    submitError.value = null;
    if (open) load();
});

function choose(kind, id) {
    if (pending.value) return;
    if (isCurrent(kind, id)) {
        pickerOpen.value = false;
        return;
    }

    pending.value = true;
    submitError.value = null;
    enter(kind, id, {
        onSuccess: () => (pickerOpen.value = false),
        onError: (errors) => (submitError.value = errors.view_as ?? 'That view is not available to you.'),
        onHttpException: onHttpException('The preview did not start', (message) => (submitError.value = message)),
        onFinish: () => (pending.value = false),
    });
}

function backToMine() {
    pending.value = true;
    exit({
        onSuccess: () => (pickerOpen.value = false),
        onHttpException: onHttpException('The preview did not end', (message) => (submitError.value = message)),
        onFinish: () => (pending.value = false),
    });
}
</script>

<template>
    <Dialog v-model:open="pickerOpen">
        <DialogContent
            data-testid="view-as-picker"
            :show-close-button="false"
            class="top-[12vh] translate-y-0 gap-0 overflow-hidden rounded-lg bg-popover p-0 shadow-panel sm:max-w-[560px]"
        >
            <DialogTitle class="sr-only">View as</DialogTitle>
            <DialogDescription class="sr-only">
                Pick a unit in your scope{{ hasPeople ? ' or a person' : '' }} to preview the portal as they see it. The preview is read-only.
            </DialogDescription>

            <Command class="rounded-none bg-transparent">
                <CommandInput :placeholder="placeholder" class="text-[14px]" aria-label="Search units and people to view as">
                    <template #prefix>
                        <span class="shrink-0 rounded-sm bg-muted px-1.5 py-0.5 text-xs font-medium text-foreground">View as</span>
                    </template>
                </CommandInput>

                <!-- Loading: rows shaped like the list that replaces them. -->
                <div v-if="status === 'loading' || status === 'idle'" class="space-y-1 p-2" aria-busy="true" data-testid="view-as-loading">
                    <span class="sr-only">Loading who you can view as</span>
                    <div v-for="row in 5" :key="row" class="flex items-center gap-3 px-2 py-2">
                        <Skeleton class="size-4 rounded-sm" />
                        <Skeleton class="h-3.5 w-24" />
                        <Skeleton class="h-3.5 w-32" />
                    </div>
                </div>

                <div v-else-if="status === 'error'" role="alert" class="flex flex-col items-center gap-3 px-6 py-10 text-center" data-testid="view-as-error">
                    <PhWarningCircle class="size-6 text-destructive" aria-hidden="true" />
                    <p class="max-w-[42ch] text-[13px] text-muted-foreground">The list of units and people did not load. Check your connection, then try again.</p>
                    <Button variant="outline" size="sm" @click="load">Try again</Button>
                </div>

                <div v-else-if="nothingToOffer && !viewAs" class="flex flex-col items-center gap-1.5 px-6 py-10 text-center" data-testid="view-as-empty">
                    <p class="text-[13.5px] font-medium">Nothing to view as</p>
                    <p class="max-w-[46ch] text-[12.5px] text-muted-foreground">
                        View as narrows the portal to a unit inside your own scope, and no unit is in yours yet. Scope comes from your
                        roster billet or a staff grant.
                    </p>
                </div>

                <CommandList v-else class="max-h-[min(420px,60vh)] p-1" data-testid="view-as-list">
                    <CommandEmpty class="px-6 py-8 text-[13px] text-muted-foreground">No unit or person matches that search.</CommandEmpty>

                    <template v-if="viewAs">
                        <CommandGroup>
                            <CommandItem value="exit" class="gap-2.5 py-2 text-[13.5px]" data-testid="view-as-back" @select="backToMine">
                                <PhArrowCounterClockwise class="size-4" aria-hidden="true" />
                                Back to my own view
                            </CommandItem>
                        </CommandGroup>
                        <CommandSeparator />
                    </template>

                    <!-- People first for an Admin: previewing a specific leader is what they reach for. -->
                    <CommandGroup v-if="hasPeople && options.people.length" heading="People">
                        <CommandItem
                            v-for="person in options.people"
                            :key="`${person.kind ?? 'user'}-${person.id}`"
                            :value="`${person.kind ?? 'user'}:${person.id}`"
                            :data-testid="`view-as-${person.kind ?? 'user'}-${person.id}`"
                            class="gap-2.5 py-2 text-[13.5px]"
                            @select="choose(person.kind ?? 'user', person.id)"
                        >
                            <PhUser class="size-4" aria-hidden="true" />
                            <span class="min-w-0 truncate">{{ person.label }}</span>
                            <Badge v-if="person.admin" variant="outline" class="shrink-0 text-[11px]">Admin</Badge>
                            <!-- On the roster but never signed in: previewed from their billet. -->
                            <Badge v-if="person.account === false" variant="outline" class="shrink-0 text-[11px] text-muted-foreground">No account yet</Badge>
                            <span v-if="person.designation" class="ml-auto shrink-0 font-mono text-[12px] text-muted-foreground">{{ person.designation }}</span>
                            <PhCheck
                                v-if="isCurrent(person.kind ?? 'user', person.id)"
                                :class="['size-4 text-foreground', person.designation ? '' : 'ml-auto']"
                                aria-label="Current view"
                            />
                        </CommandItem>
                    </CommandGroup>
                    <CommandGroup v-if="options.units.length" heading="Units in your scope">
                        <CommandItem
                            v-for="unit in options.units"
                            :key="`unit-${unit.id}`"
                            :value="`unit:${unit.id}`"
                            :data-testid="`view-as-unit-${unit.id}`"
                            class="gap-2.5 py-2 text-[13.5px]"
                            @select="choose('unit', unit.id)"
                        >
                            <PhUsersThree class="size-4" aria-hidden="true" />
                            <span class="shrink-0 font-mono text-[13px]">{{ unit.designation }}</span>
                            <span v-if="unit.name !== unit.designation" class="min-w-0 truncate text-muted-foreground">{{ unit.name }}</span>
                            <PhCheck v-if="isCurrent('unit', unit.id)" class="ml-auto size-4 text-foreground" aria-label="Current view" />
                        </CommandItem>
                    </CommandGroup>

                </CommandList>

                <p v-if="submitError" role="alert" class="border-t px-4 py-2.5 text-[12.5px] text-destructive" data-testid="view-as-submit-error">
                    {{ submitError }}
                </p>
                <p v-else class="border-t px-4 py-2 text-[12px] text-muted-foreground">
                    Read-only preview. Exit view as to make changes.
                </p>
            </Command>
        </DialogContent>
    </Dialog>
</template>
