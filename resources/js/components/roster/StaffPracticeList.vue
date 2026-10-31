<script setup>
import { PhTrash } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { weekLabel } from '@/lib/roster';
import { staffPracticeKey, staffPracticeSource } from '@/lib/staffPractices';

// The section practices a staff member attended (#448), newest week first, on the milpac and the roster Sheet. The
// server shapes each entry (`Milpac\StaffPracticeHistory`); this words it. An entry the viewer may remove (`removable`,
// only ever sent on the milpac) gets a Remove button, which emits `remove`.
defineProps({
    entries: { type: Array, required: true },
    busy: { type: Boolean, default: false },
});
defineEmits(['remove']);
</script>

<template>
    <ul class="space-y-1.5" data-testid="staff-practices">
        <li v-for="entry in entries" :key="staffPracticeKey(entry)" class="flex items-start gap-2 text-[13px]" data-testid="staff-practice" :data-source="entry.source">
            <span class="min-w-0 flex-1">
                <span class="font-medium tabular-nums">{{ weekLabel(entry.week) }}</span>, {{ entry.section ?? 'a section no longer in the portal' }}
                <span class="block text-[11px] text-muted-foreground" data-testid="staff-practice-source">{{ staffPracticeSource(entry) }}</span>
            </span>
            <Button
                v-if="entry.removable"
                variant="ghost"
                size="sm"
                class="text-destructive hover:text-destructive"
                :disabled="busy"
                data-testid="staff-practice-remove"
                @click="$emit('remove', entry)"
            >
                <PhTrash aria-hidden="true" />Remove<span class="sr-only"> the week {{ weekLabel(entry.week) }} at {{ entry.section }}</span>
            </Button>
        </li>
    </ul>
</template>
