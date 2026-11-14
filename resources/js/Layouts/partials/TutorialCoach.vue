<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { PhCaretDown, PhCaretUp, PhCheckCircle, PhCircle } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { leaving, recordClick, useTutorial } from '@/composables/useTutorial';
import { coachSide, onStepPage, targetCss } from '@/lib/tutorial';

// The coach card beside the page while the tutorial is on: the step's instruction, its checklist and Next/Back. The
// checklist ticks itself from the person's practice copy: the shared `tutorialMode` prop is read again every few
// seconds, and soon after each click, as some actions (the roll call autosave) are not page visits.
const { active, steps, index, step, stepDone, next, skip, back } = useTutorial();
const page = usePage();
const open = ref(true);
const card = ref(null);

const REFRESH_MS = 4000;
const PLACE_MS = 400;
// After a click: once the page's own save is likely done, and once more for a slow one.
const AFTER_CLICK_MS = [800, 2500];
let timers = [];
let clickTimers = [];

function refresh() {
    if (!active.value || leaving.value || document.hidden) return;
    router.reload({ only: ['tutorialMode'], async: true, preserveScroll: true, preserveState: true, showProgress: false });
}

function onClick(event) {
    if (!active.value || card.value?.contains(event.target)) return;
    recordClick(steps.value, event.target);
    clickTimers.forEach((id) => window.clearTimeout(id));
    clickTimers = AFTER_CLICK_MS.map((ms) => window.setTimeout(refresh, ms));
}

// What to show in the tutorial blue: on the step's pages (`step.area`, one path or several, else its own page), what the step points at
// (`step.highlight`, such as Demo.K's card); elsewhere, the sidebar link to that page (`step.nav`).
const target = computed(() => {
    if (!active.value || !step.value) return null;
    // A finished step points nowhere: the reader moves on with Next step.
    if (step.value.done) return null;
    if (step.value.nav && ![step.value.area ?? step.value.href].flat().some((href) => onStepPage(page.url, href))) return step.value.nav;

    return step.value.highlight ?? null;
});

let style = null;
watch(
    target,
    (selector) => {
        if (typeof document === 'undefined') return;
        style ??= document.getElementById('tutorial-target-style') ?? Object.assign(document.createElement('style'), { id: 'tutorial-target-style' });
        if (!style.isConnected) document.head.appendChild(style);
        style.textContent = targetCss(selector);
    },
    { immediate: true },
);

// The card keeps clear of what the step points at and of an open side panel (a trooper's panel on the roster): it
// moves to the bottom left while either is under it, and above the panel's backdrop while one is open.
const side = ref('right');
const overPanel = ref(false);
const PANELS = '[data-slot="sheet-content"]';

function place() {
    if (!card.value) return;
    const panels = [...document.querySelectorAll(PANELS)];
    let pointed = [];
    try {
        pointed = target.value ? [...document.querySelectorAll(target.value)] : [];
    } catch {
        pointed = [];
    }
    const rect = card.value.getBoundingClientRect();
    side.value = coachSide(
        { width: rect.width, top: rect.top, bottom: rect.bottom },
        window.innerWidth,
        [...pointed, ...panels].map((el) => el.getBoundingClientRect()),
    );
    overPanel.value = panels.length > 0;
}

// Back in the real portal, a later start of the tutorial refreshes again.
watch(active, (on) => !on && (leaving.value = false));

onMounted(() => {
    timers = [window.setInterval(refresh, REFRESH_MS), window.setInterval(place, PLACE_MS)];
    document.addEventListener('click', onClick, true);
    place();
});
onBeforeUnmount(() => {
    // Interval and timeout ids share one pool, so clearTimeout ends both.
    [...timers, ...clickTimers].forEach((id) => window.clearTimeout(id));
    document.removeEventListener('click', onClick, true);
    style?.remove();
    style = null;
});
</script>

<template>
    <aside
        v-if="active && step"
        aria-labelledby="tutorial-coach-title"
        data-testid="tutorial-coach"
        ref="card"
        :data-side="side"
        :class="[
            'pointer-events-auto fixed bottom-4 w-[min(22rem,calc(100vw-2rem))] rounded-lg border-2 border-changelog bg-card text-card-foreground shadow-lg',
            side === 'left' ? 'left-4' : 'right-4',
            overPanel ? 'z-[60]' : 'z-40',
        ]"
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
                <span v-else-if="steps.every((s) => s.done)" class="ml-auto inline-flex items-center gap-1.5 text-sm font-medium" data-testid="tutorial-last">
                    <PhCheckCircle weight="fill" class="size-4 text-changelog dark:text-foreground" aria-hidden="true" />Finished
                </span>
                <span v-else class="ml-auto text-sm font-medium" data-testid="tutorial-last">Last step</span>
            </div>
        </div>
    </aside>
</template>
