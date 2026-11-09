<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { PhCaretDown, PhCaretUp, PhCheckCircle, PhCircle } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { leaving, useTutorial } from '@/composables/useTutorial';

// The coach card beside the page while the tutorial is on: the step's instruction, its checklist and Next/Back. The
// checklist ticks itself from the person's practice copy: the shared `tutorial` prop is read again every few seconds,
// as some actions (the roll call autosave) are not page visits.
const { active, steps, index, step, stepDone, next, skip, back } = useTutorial();
const open = ref(true);

const REFRESH_MS = 4000;
let timer = null;

function refresh() {
    if (!active.value || leaving.value || document.hidden) return;
    router.reload({ only: ['tutorialMode'], async: true, preserveScroll: true, preserveState: true, showProgress: false });
}

// What the step points at (`step.highlight`, a selector such as Demo.K's card) gets the tutorial blue ring. Applied
// again after each refresh and page change, as the page may draw the element later.
const RING = 'tutorial-target';
function ring() {
    document.querySelectorAll(`.${RING}`).forEach((el) => el.classList.remove(RING));
    const selector = active.value ? step.value?.highlight : null;
    if (selector) document.querySelectorAll(selector).forEach((el) => el.classList.add(RING));
}
watch(() => [active.value, step.value?.highlight], () => nextTick(ring), { immediate: true });
// Back in the real portal, a later start of the tutorial refreshes again.
watch(active, (on) => !on && (leaving.value = false));
const page = usePage();
watch(() => page.url, () => nextTick(ring));

onMounted(() => (timer = window.setInterval(() => (refresh(), ring()), REFRESH_MS)));
onBeforeUnmount(() => {
    window.clearInterval(timer);
    document.querySelectorAll(`.${RING}`).forEach((el) => el.classList.remove(RING));
});
</script>

<template>
    <aside
        v-if="active && step"
        aria-labelledby="tutorial-coach-title"
        data-testid="tutorial-coach"
        class="fixed right-4 bottom-4 z-40 w-[min(22rem,calc(100vw-2rem))] rounded-lg border-2 border-changelog bg-card text-card-foreground shadow-lg"
    >
        <div class="flex items-center gap-2 px-4 pt-3">
            <p class="text-xs font-semibold text-changelog dark:text-foreground">Step {{ index + 1 }} of {{ steps.length }}</p>
            <button
                type="button"
                class="ml-auto rounded-sm p-1 text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                :aria-expanded="open"
                aria-controls="tutorial-coach-body"
                :aria-label="open ? 'Hide the step' : 'Show the step'"
                data-testid="tutorial-coach-toggle"
                @click="open = !open"
            >
                <PhCaretDown v-if="open" aria-hidden="true" />
                <PhCaretUp v-else aria-hidden="true" />
            </button>
        </div>
        <h2 id="tutorial-coach-title" class="px-4 pb-1 text-base font-semibold tracking-tight">{{ step.title }}</h2>
        <div v-show="open" id="tutorial-coach-body" class="px-4 pb-4">
            <p class="text-sm text-muted-foreground">{{ step.body }}</p>
            <ol class="mt-3 space-y-1.5 text-sm" data-testid="tutorial-checklist">
                <li v-for="item in step.checklist" :key="item.label" class="flex items-start gap-2" :data-done="item.done">
                    <PhCheckCircle v-if="item.done" weight="fill" class="mt-0.5 size-4 shrink-0 text-changelog dark:text-foreground" aria-hidden="true" />
                    <PhCircle v-else class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span :class="item.done ? 'text-muted-foreground line-through' : ''">{{ item.label }}</span>
                    <span class="sr-only">{{ item.done ? '(done)' : '(to do)' }}</span>
                </li>
            </ol>
            <p v-if="step.note" class="mt-3 rounded-md bg-muted px-2.5 py-2 text-xs text-muted-foreground">{{ step.note }}</p>
            <div class="mt-4 flex items-center gap-2">
                <Button variant="outline" size="sm" :disabled="index === 0" data-testid="tutorial-back" @click="back">Back</Button>
                <Link :href="step.href" class="text-sm text-muted-foreground underline underline-offset-2 hover:text-foreground">Go to the page</Link>
                <template v-if="index < steps.length - 1">
                    <Button v-if="!stepDone" variant="ghost" size="sm" class="ml-auto" data-testid="tutorial-skip" @click="skip">Skip</Button>
                    <Button
                        size="sm"
                        :class="['bg-changelog text-white hover:bg-changelog/90', stepDone ? 'ml-auto' : '']"
                        :disabled="!stepDone"
                        :title="stepDone ? undefined : 'Finish every item above first, or Skip'"
                        data-testid="tutorial-next"
                        @click="next"
                    >
                        Next step
                    </Button>
                </template>
                <span v-else class="ml-auto text-sm font-medium" data-testid="tutorial-last">Last step</span>
            </div>
        </div>
    </aside>
</template>
