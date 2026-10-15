<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { displayKeys, isShortcut, isTypingTarget, keyLabel, keyName, overlayOpen, SHORTCUT_GROUPS } from '@/lib/shortcuts';
import { isActive } from '../navigation';

// The `?` list of every keyboard shortcut, built from lib/shortcuts.js. Mounted once in the app
// shell, so `?` works on every page. It stays out of the way while the viewer types in a field or
// another dialog, sheet or menu is open.
const open = ref(false);
const page = usePage();

// "Everywhere" first, then the page the viewer is on, then the rest in nav order.
const groups = computed(() =>
    SHORTCUT_GROUPS.map((group) => ({ ...group, current: Boolean(group.href) && isActive(page.url, group.href) })).sort(
        (a, b) => (a.href === null ? -1 : b.href === null ? 1 : Number(b.current) - Number(a.current)),
    ),
);

// "1 to 4" for a number range, "J or ↓" for a pair; a longer set (the four arrows) reads fine bare.
function separator(shortcut) {
    if (shortcut.range) return 'to';

    return shortcut.keys.length === 2 ? 'or' : null;
}

function onKeydown(event) {
    if (open.value || event.defaultPrevented || !isShortcut(event, 'help')) return;
    if (isTypingTarget(event.target) || overlayOpen()) return;
    event.preventDefault();
    open.value = true;
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[min(40rem,calc(100dvh-2rem))] gap-0 overflow-y-auto p-0 sm:max-w-xl" data-testid="shortcuts-dialog">
            <DialogHeader class="border-b border-border px-5 pt-5 pb-3.5">
                <DialogTitle class="text-[15px]">Keyboard shortcuts</DialogTitle>
                <DialogDescription class="text-[13px]">
                    Press <kbd class="rounded-sm border border-border bg-muted px-1 font-mono text-[11px]">?</kbd> on any page to open this list.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-5 px-5 py-4">
                <section v-for="group in groups" :key="group.id" :aria-labelledby="`shortcuts-${group.id}`" :data-testid="`shortcuts-group-${group.id}`">
                    <h3 :id="`shortcuts-${group.id}`" class="mb-1.5 flex items-center gap-2 text-[13px] font-semibold">
                        {{ group.title }}
                        <Badge v-if="group.current" variant="outline" class="font-normal">This page</Badge>
                    </h3>
                    <ul class="divide-y divide-border">
                        <li v-for="shortcut in group.shortcuts" :key="shortcut.id" class="flex items-start justify-between gap-4 py-1.5 text-[13px]" :data-testid="`shortcut-${shortcut.id}`">
                            <span class="min-w-0">
                                {{ shortcut.label }}
                                <span v-if="shortcut.where" class="block text-xs text-muted-foreground">{{ shortcut.where }}</span>
                            </span>
                            <span class="flex shrink-0 flex-wrap items-center justify-end gap-1 text-xs text-muted-foreground">
                                <template v-for="(key, index) in displayKeys(shortcut)" :key="key">
                                    <span v-if="index > 0 && separator(shortcut)">{{ separator(shortcut) }}</span>
                                    <kbd class="min-w-5 rounded-sm border border-border bg-muted px-1 text-center font-mono text-[11px] text-foreground">
                                        <span aria-hidden="true">{{ keyLabel(key) }}</span><span class="sr-only">{{ keyName(key) }}</span>
                                    </kbd>
                                </template>
                            </span>
                        </li>
                    </ul>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
