<script setup>
import { computed } from 'vue';
import { diffCounts, diffLines, foldUnchanged } from '@/lib/lineDiff';

// Two texts compared line by line (`lib/lineDiff.js`): a removed line is only in `before`, an added line only in `after`.
// Each changed line carries a + or − and a hidden "Added" or "Removed" for a screen reader, so the colour is never the
// only sign. Long runs of unchanged lines fold away, keeping `context` lines around each change.
const props = defineProps({
    before: { type: String, required: true },
    after: { type: String, required: true },
    context: { type: Number, default: 3 },
});

const lines = computed(() => diffLines(props.before, props.after));
const counts = computed(() => diffCounts(lines.value));
const rows = computed(() => foldUnchanged(lines.value, props.context));
const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;

const MARK = { same: ' ', removed: '−', added: '+' };
const SPOKEN = { removed: 'Removed: ', added: 'Added: ' };
// A blank line keeps its height.
const BLANK = String.fromCharCode(160);
</script>

<template>
    <div class="space-y-2">
        <p class="text-xs text-muted-foreground" data-testid="diff-summary">
            <template v-if="counts.added || counts.removed">
                <span class="font-medium text-success">{{ plural(counts.added, 'line') }} added</span>,
                <span class="font-medium text-destructive">{{ plural(counts.removed, 'line') }} removed</span>
            </template>
            <template v-else>No differences.</template>
        </p>
        <div v-if="counts.added || counts.removed" class="max-h-96 overflow-auto rounded-md border border-border bg-muted/30 py-1 font-mono text-xs" data-testid="diff-lines">
            <template v-for="(row, index) in rows" :key="index">
                <div v-if="row.type === 'skip'" class="border-y border-border/60 bg-muted/60 px-3 py-0.5 text-muted-foreground" data-testid="diff-skip">
                    {{ plural(row.count, 'unchanged line') }}
                </div>
                <div
                    v-else
                    class="grid grid-cols-[2.5rem_2.5rem_1.25rem_1fr]"
                    :class="{ 'bg-success/10': row.type === 'added', 'bg-destructive/10': row.type === 'removed' }"
                    :data-testid="`diff-${row.type}`"
                >
                    <span class="pr-2 text-right text-muted-foreground tabular-nums select-none" aria-hidden="true">{{ row.oldLine ?? '' }}</span>
                    <span class="pr-2 text-right text-muted-foreground tabular-nums select-none" aria-hidden="true">{{ row.newLine ?? '' }}</span>
                    <span
                        class="text-center font-semibold select-none"
                        :class="{ 'text-success': row.type === 'added', 'text-destructive': row.type === 'removed' }"
                        aria-hidden="true"
                    >{{ MARK[row.type] }}</span>
                    <span class="pr-3 break-all whitespace-pre-wrap text-foreground"><span v-if="SPOKEN[row.type]" class="sr-only">{{ SPOKEN[row.type] }}</span>{{ row.text === '' ? BLANK : row.text }}</span>
                </div>
            </template>
        </div>
    </div>
</template>
