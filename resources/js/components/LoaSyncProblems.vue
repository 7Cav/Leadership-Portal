<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { PhWarning } from '@phosphor-icons/vue';
import { forumDateTime } from '@/lib/forumDate';

// What the latest leave sync could not read, for an Admin (2026-10-29). `run` is `LoaSyncProblems::latest()`: the
// threads it could not read, how many posts it read but could not make sense of, and why a failed run stopped. Only
// what the sync already recorded; nothing here asks the forum. Draws nothing for a run with no problems.
const props = defineProps({
    run: { type: Object, required: true },
});

const page = usePage();
const forumBase = computed(() => String(page.props.forum?.baseUrl ?? '').replace(/\/+$/, ''));
const threadHref = (id) => (id && /^https?:\/\/[^/\s]+/i.test(forumBase.value) ? `${forumBase.value}/threads/${id}/` : null);

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;
const hasProblems = computed(() => props.run.status === 'failure' || props.run.unread > 0 || props.run.needsReview > 0);
const summary = computed(() => {
    const at = props.run.finishedAt ? `${forumDateTime(props.run.finishedAt)}Z` : null;
    const head = `Latest leave sync${at ? `, ${at}` : ''}`;
    if (props.run.status === 'failure') return `${head}: failed`;

    const parts = [];
    if (props.run.unread > 0) parts.push(`${plural(props.run.unread, 'problem', 'problems')} reading the forum`);
    if (props.run.needsReview > 0) parts.push(`${plural(props.run.needsReview, 'post needs', 'posts need')} review`);

    return `${head}: ${parts.join(', ')}`;
});
</script>

<template>
    <details v-if="hasProblems" class="rounded-md border border-border bg-card px-3 py-2 text-xs" data-testid="loa-sync-problems">
        <summary class="flex cursor-pointer items-center gap-1.5 text-warning">
            <PhWarning class="size-3.5 shrink-0" aria-hidden="true" />{{ summary }}
        </summary>
        <div class="mt-2 space-y-2 text-muted-foreground">
            <p v-if="run.failure" data-testid="loa-sync-failure">The sync stopped: {{ run.failure }}</p>
            <ul v-if="run.problems.length" class="space-y-1" data-testid="loa-sync-unread">
                <li v-for="(problem, index) in run.problems" :key="index">
                    <a
                        v-if="threadHref(problem.threadId)"
                        :href="threadHref(problem.threadId)"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="underline underline-offset-2 hover:text-foreground"
                        >{{ problem.text }}<span class="sr-only"> (opens the forum in a new tab)</span></a
                    >
                    <template v-else>{{ problem.text }}</template>
                </li>
            </ul>
            <p v-if="run.unread > run.problems.length">{{ run.unread - run.problems.length }} more not listed.</p>
            <p v-if="run.unread > 0 && run.status !== 'failure'">Threads it could not read are tried again by later syncs.</p>
            <p v-if="run.needsReview > 0">
                A post that needs review is stored without dates. Leaders see it on the rollcall and in Home's Needs attention; the trooper fixes it on the
                forum and the next sync reads it again.
            </p>
        </div>
    </details>
</template>
