<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { PhArrowLeft, PhArrowRight, PhArrowSquareOut, PhCheck, PhCheckCircle, PhClock, PhFlagPennant } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import DocsLayout from '@/components/docs/DocsLayout.vue';
import GuideFeedback from '@/components/docs/GuideFeedback.vue';
import { useTutorialProgress } from '@/composables/useTutorialProgress';
import { openablePage } from '@/lib/docs';
import { Checkbox } from '@/components/ui/checkbox';

// One step of the getting started tutorial: "Step 3 of 8" with a bar of every step, the step's text (the same
// renderer as a guide), "Try it" into the real portal page, and Back and Next. Moving on with Next marks the step
// done; the step list on the right (below on a phone) shows which are done, kept in this browser.
const props = defineProps({
    audience: { type: String, required: true },
    contents: { type: Array, required: true },
    feedbackUrl: { type: String, default: null },
    // The tutorial step ids the portal ticks off by itself (app/Docs/PortalProgress).
    portalDone: { type: Array, default: () => [] },
    search: { type: Array, required: true },
    // The step, shaped as a guide: `{ title, summary, url, page, try, minutes, html, headings, ... }`.
    guide: { type: Object, required: true },
    previous: { type: Object, default: null },
    next: { type: Object, default: null },
    // `[{ id, title, url }]`: every step of the tutorial, in order.
    steps: { type: Array, required: true },
    tutorial: { type: Object, default: null },
});

const page = usePage();
const progress = useTutorialProgress(
    () => props.steps,
    () => ({ userId: page.props.auth?.user?.id ?? null, previewing: page.props.viewAs != null, portal: page.props.portalDone ?? [] }),
);

const index = computed(() => props.steps.findIndex((step) => step.url === props.guide.url));
const step = computed(() => props.steps[index.value] ?? null);
const tryIt = computed(() => {
    const href = openablePage(props.guide.page);

    return href && props.guide.try ? { href, label: props.guide.try } : null;
});

// The portal already knows this step is done: the tick is set and cannot be undone here.
const portalDone = computed(() => (step.value ? progress.byPortal(step.value.id) : false));

function toggleDone() {
    if (step.value) progress.mark(step.value.id, !progress.isDone(step.value.id));
}

// Next, or Finish on the last step: both mark this step done first.
function advance() {
    if (step.value) progress.mark(step.value.id, true);
    router.visit(props.next?.url ?? '/docs');
}
</script>

<template>
    <Head :title="`${guide.title} · Getting started · Docs · 7Cav Leadership Portal`" />

    <DocsLayout :contents="contents" :current="guide.url" :search="search" :tutorial="tutorial">
        <article class="max-w-[680px]" data-testid="tutorial-step">
            <nav aria-label="Breadcrumb" class="mb-3 text-[13px] text-muted-foreground">
                <ol class="flex flex-wrap items-center gap-1">
                    <li><Link href="/docs" class="hover:text-foreground hover:underline">Docs</Link></li>
                    <li aria-hidden="true">/</li>
                    <li class="font-medium text-gold-ink">Getting started</li>
                </ol>
            </nav>

            <div class="mb-6">
                <p class="mb-2 flex items-center gap-2 text-[13px] font-semibold" data-testid="tutorial-position">
                    <PhFlagPennant weight="bold" class="text-[var(--nav-active-icon)]" aria-hidden="true" />
                    Step {{ index + 1 }} of {{ steps.length }}
                    <span class="ml-auto font-normal text-muted-foreground tabular-nums">{{ progress.count.value }} done</span>
                </p>
                <ol class="flex gap-1" aria-label="Tutorial steps">
                    <li v-for="(s, i) in steps" :key="s.id" class="flex-1">
                        <Link
                            :href="s.url"
                            class="block h-1.5 rounded-full transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                            :class="i === index ? 'bg-foreground' : progress.isDone(s.id) ? 'bg-primary' : 'bg-border hover:bg-input'"
                            :aria-current="i === index ? 'step' : undefined"
                            :aria-label="`Step ${i + 1}: ${s.title}${progress.isDone(s.id) ? ', done' : ''}`"
                            :data-testid="`tutorial-bar-${s.id}`"
                        />
                    </li>
                </ol>
            </div>

            <h1 class="mb-2.5 text-[32px] leading-[1.15] font-semibold tracking-tight text-balance">{{ guide.title }}</h1>
            <p class="mb-3 max-w-[60ch] text-[17px] leading-relaxed text-muted-foreground">{{ guide.summary }}</p>
            <p v-if="guide.minutes" class="mb-6 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                <PhClock aria-hidden="true" />About {{ guide.minutes }} {{ guide.minutes === 1 ? 'minute' : 'minutes' }}
            </p>

            <!-- eslint-disable-next-line vue/no-v-html -- server-rendered markdown, raw HTML stripped (#408) -->
            <div class="docs-prose" data-testid="guide-body" v-html="guide.html" />

            <section v-if="tryIt" class="mt-8 flex flex-col gap-3 rounded-lg border border-border bg-card p-4 sm:flex-row sm:items-center" aria-label="Try it">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold">Try it</p>
                    <p class="text-[13px] text-muted-foreground">Open the page and look for what this step describes. Come back here with your browser's back button.</p>
                </div>
                <Button as-child variant="outline">
                    <Link :href="tryIt.href" data-testid="tutorial-try"><PhArrowSquareOut />{{ tryIt.label }}</Link>
                </Button>
            </section>

            <label for="tutorial-done" class="mt-6 flex w-fit items-center gap-2 text-sm" :class="portalDone ? '' : 'cursor-pointer'">
                <Checkbox
                    id="tutorial-done"
                    :model-value="step ? progress.isDone(step.id) : false"
                    :disabled="portalDone"
                    :aria-describedby="portalDone ? 'tutorial-done-hint' : undefined"
                    data-testid="tutorial-done"
                    @update:model-value="toggleDone"
                />
                I have done this step
            </label>
            <p v-if="portalDone" id="tutorial-done-hint" class="mt-1 ml-6 text-[13px] text-muted-foreground" data-testid="tutorial-done-hint">
                Done in the portal
            </p>

            <nav class="mt-8 flex items-center gap-3 border-t border-border pt-5" aria-label="Tutorial">
                <Button v-if="previous" as-child variant="ghost">
                    <Link :href="previous.url" rel="prev" data-testid="tutorial-back"><PhArrowLeft />Back</Link>
                </Button>
                <Button class="ml-auto" data-testid="tutorial-next" @click="advance">
                    <template v-if="next">Next step<PhArrowRight /></template>
                    <template v-else><PhCheck weight="bold" />Finish the tutorial</template>
                </Button>
            </nav>

            <GuideFeedback :url="feedbackUrl" />
        </article>

        <template #rail>
            <nav class="rounded-lg border border-border bg-card p-3.5 text-[13px]" aria-labelledby="tutorial-steps-title" data-testid="tutorial-steps">
                <h2 id="tutorial-steps-title" class="mb-2 text-sm font-semibold tracking-tight">Getting started</h2>
                <ol>
                    <li v-for="(s, i) in steps" :key="s.id">
                        <Link
                            :href="s.url"
                            class="flex items-center gap-2.5 rounded-md px-1.5 py-1.5 hover:bg-accent"
                            :class="i === index ? 'bg-primary/15 font-semibold' : 'text-muted-foreground'"
                            :aria-current="i === index ? 'step' : undefined"
                        >
                            <PhCheckCircle v-if="progress.isDone(s.id)" weight="fill" class="size-[18px] flex-none text-success" aria-label="Done" />
                            <span
                                v-else
                                class="grid size-[18px] flex-none place-items-center rounded-full border border-current text-[10px] font-semibold tabular-nums"
                                aria-hidden="true"
                                >{{ i + 1 }}</span
                            >
                            <span class="min-w-0">{{ s.title }}</span>
                        </Link>
                    </li>
                </ol>
            </nav>
        </template>
    </DocsLayout>
</template>
