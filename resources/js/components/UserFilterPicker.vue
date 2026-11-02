<script setup>
import { computed, ref } from 'vue';
import { PhCaretUpDown, PhCheck } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

// The "User" filter on the Audit log and the Activity log: a button that opens a searchable list of everyone the
// log names, alphabetical. The whole list is already on the page (the server sends only the people who appear in
// that log), so the search runs here. `v-model` is the chosen user's id, or null for anyone.
const props = defineProps({
    users: { type: Array, required: true },
    id: { type: String, default: undefined },
    testid: { type: String, default: undefined },
});

const model = defineModel({ type: Number, default: null });

const open = ref(false);

const sorted = computed(() => [...props.users].sort((a, b) => a.username.localeCompare(b.username, undefined, { sensitivity: 'base' })));

const selected = computed(() => props.users.find((user) => user.id === model.value) ?? null);

// The command list searches each row's text, so a row also carries its name with the dot as a space ("Pike S"),
// hidden, and either form finds it.
const spaced = (user) => (user.username.includes('.') ? user.username.replace(/\./g, ' ') : null);

function pick(id) {
    model.value = id;
    open.value = false;
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                type="button"
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                class="w-full justify-between font-normal"
                :data-testid="testid"
                :data-value="model ?? ''"
            >
                <span class="truncate" :class="selected ? '' : 'text-muted-foreground'">{{ selected?.username ?? 'Any user' }}</span>
                <PhCaretUpDown class="opacity-50" aria-hidden="true" />
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-72 p-0" align="start" data-testid="user-filter-popover">
            <Command>
                <CommandInput placeholder="Search users" aria-label="Search users" />
                <CommandList class="max-h-[min(320px,50vh)]">
                    <CommandEmpty>No user matches that search.</CommandEmpty>
                    <CommandGroup>
                        <CommandItem value="Any user" data-user="" @select="pick(null)">
                            <span class="text-muted-foreground" data-name>Any user</span>
                            <PhCheck v-if="model === null" class="ml-auto" aria-label="Selected" />
                        </CommandItem>
                        <CommandItem v-for="user in sorted" :key="user.id" :value="user.username" :data-user="user.id" @select="pick(user.id)">
                            <span data-name>{{ user.username }}</span>
                            <span v-if="spaced(user)" hidden aria-hidden="true">{{ spaced(user) }}</span>
                            <PhCheck v-if="user.id === model" class="ml-auto" aria-label="Selected" />
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
