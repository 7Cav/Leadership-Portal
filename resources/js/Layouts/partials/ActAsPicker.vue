<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { PhUser, PhWarningCircle } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { useActAs } from '@/composables/useActAs';

// The act-as picker (#432): a leader steps into a serving billet holder below them. The list is fetched each time it
// opens and holds only people the server would accept right now; it is a convenience, since the server checks the choice
// again when it is made and on every request after. Choosing asks to confirm, because everything done is real.
const { pickerOpen } = useActAs();

const status = ref('idle'); // idle | loading | ready | error
const people = ref([]);
const chosen = ref(null);
const starting = ref(false);
const submitError = ref(null);

const nothingToOffer = computed(() => people.value.length === 0);

let loadId = 0;

async function load() {
    // A slower, older answer must not overwrite a newer one: it could offer someone no longer valid.
    const mine = ++loadId;
    status.value = 'loading';
    try {
        const response = await fetch('/act-as/options', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const body = await response.json();
        if (!Array.isArray(body?.people)) throw new Error('Unexpected response');
        if (mine !== loadId) return;
        people.value = body.people;
        status.value = 'ready';
    } catch (error) {
        if (mine !== loadId) return;
        console.error('Act as: the list did not load', error);
        status.value = 'error';
    }
}

watch(pickerOpen, (open) => {
    chosen.value = null;
    submitError.value = null;
    if (open) load();
});

function start() {
    starting.value = true;
    submitError.value = null;
    router.post(
        '/act-as',
        { kind: 'user', id: chosen.value.id },
        {
            onSuccess: () => (pickerOpen.value = false),
            onError: (errors) => {
                submitError.value = errors.act_as ?? 'You cannot act as this person.';
                load();
            },
            onException: () => (submitError.value = 'Could not start acting. Try again.'),
            onFinish: () => (starting.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="pickerOpen">
        <DialogContent
            data-testid="act-as-picker"
            :show-close-button="false"
            class="top-[12vh] translate-y-0 gap-0 overflow-hidden rounded-lg bg-popover p-0 shadow-panel sm:max-w-[560px]"
        >
            <DialogTitle class="sr-only">Act as</DialogTitle>
            <DialogDescription class="sr-only">Choose a serving billet holder below you to act as them, with exactly their permissions.</DialogDescription>

            <div v-if="chosen" class="space-y-3 p-4" data-testid="act-as-picker-confirm">
                <p class="text-base leading-snug font-semibold">Act as {{ chosen.label }}?</p>
                <p class="text-[13px] text-muted-foreground">
                    You get exactly their permissions and lose your own until you stop. Everything you change is real, and the audit log
                    records it as you acting for them.
                </p>
                <p v-if="submitError" role="alert" class="text-[12.5px] text-destructive" data-testid="act-as-picker-error">{{ submitError }}</p>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button variant="outline" :disabled="starting" data-testid="act-as-picker-cancel" @click="chosen = null">Cancel</Button>
                    <Button variant="destructive" :disabled="starting" data-testid="act-as-picker-confirm-button" @click="start">Act as them</Button>
                </div>
            </div>

            <Command v-else class="rounded-none bg-transparent">
                <CommandInput placeholder="Find a person" class="text-[14px]" aria-label="Search people to act as">
                    <template #prefix>
                        <span class="shrink-0 rounded-sm bg-destructive px-1.5 py-0.5 text-xs font-medium text-white">Act as</span>
                    </template>
                </CommandInput>

                <div v-if="status === 'loading' || status === 'idle'" class="space-y-1 p-2" aria-busy="true" data-testid="act-as-loading">
                    <span class="sr-only">Loading who you can act as</span>
                    <div v-for="row in 4" :key="row" class="flex items-center gap-3 px-2 py-2">
                        <Skeleton class="size-4 rounded-sm" />
                        <Skeleton class="h-3.5 w-32" />
                    </div>
                </div>

                <div v-else-if="status === 'error'" role="alert" class="flex flex-col items-center gap-3 px-6 py-10 text-center" data-testid="act-as-error">
                    <PhWarningCircle class="size-6 text-destructive" aria-hidden="true" />
                    <p class="max-w-[42ch] text-[13px] text-muted-foreground">The list of people did not load. Check your connection, then try again.</p>
                    <Button variant="outline" size="sm" @click="load">Try again</Button>
                </div>

                <div v-else-if="nothingToOffer" class="flex flex-col items-center gap-1.5 px-6 py-10 text-center" data-testid="act-as-empty">
                    <p class="text-[13.5px] font-medium">Nobody to act as</p>
                    <p class="max-w-[46ch] text-[12.5px] text-muted-foreground">
                        You can act as a serving billet holder below you in your own scope, and there is none right now.
                    </p>
                </div>

                <CommandList v-else class="max-h-[min(420px,60vh)] p-1" data-testid="act-as-list">
                    <CommandEmpty class="px-6 py-8 text-[13px] text-muted-foreground">No one matches that search.</CommandEmpty>
                    <CommandGroup heading="Billet holders below you">
                        <CommandItem
                            v-for="person in people"
                            :key="person.id"
                            :value="`user:${person.id}`"
                            :data-testid="`act-as-user-${person.id}`"
                            class="gap-2.5 py-2 text-[13.5px]"
                            @select="chosen = person"
                        >
                            <PhUser class="size-4" aria-hidden="true" />
                            <span class="min-w-0 truncate">{{ person.label }}</span>
                            <span v-if="person.designation" class="ml-auto shrink-0 font-mono text-[12px] text-muted-foreground">{{ person.designation }}</span>
                        </CommandItem>
                    </CommandGroup>
                </CommandList>

                <p class="border-t px-4 py-2 text-[12px] text-muted-foreground">Everything you change while acting is real and recorded as you.</p>
            </Command>
        </DialogContent>
    </Dialog>
</template>
