<script setup>
import { PhGraduationCap } from '@phosphor-icons/vue';
import { dotState, stepLabel } from '@/lib/tutorial';
import { useTutorial } from '@/composables/useTutorial';

// The tutorial bar: the --changelog blue, shaped like the act-as bar, on every page while the request runs on the
// person's practice copy. The step dots and label follow the coach card.
const { active, steps, index, restart, exit } = useTutorial();
</script>

<template>
    <div
        v-if="active"
        role="status"
        data-testid="tutorial-banner"
        class="flex h-9 items-center gap-2.5 bg-changelog px-3 text-[13px] text-white sm:px-4"
    >
        <PhGraduationCap class="size-4 shrink-0" weight="bold" aria-hidden="true" />
        <p class="min-w-0 truncate">
            <b class="font-semibold">Tutorial</b><span class="hidden lg:inline">. Works like the portal, with practice data. Nothing you do here counts.</span>
        </p>
        <div class="hidden items-center gap-1 sm:flex" :aria-label="stepLabel(steps, index)" data-testid="tutorial-dots">
            <i
                v-for="(step, i) in steps"
                :key="step.id"
                :data-state="dotState(step, i, index)"
                class="block size-2 rounded-full border border-white/80"
                :class="{ 'bg-white': dotState(step, i, index) === 'done', 'w-4 bg-white/70': dotState(step, i, index) === 'now' }"
            />
        </div>
        <span class="hidden min-w-0 truncate md:inline" data-testid="tutorial-step-label">{{ stepLabel(steps, index) }}</span>
        <span class="flex-1" />
        <button
            type="button"
            data-testid="tutorial-restart"
            class="shrink-0 rounded-sm font-medium whitespace-nowrap underline underline-offset-2 outline-none hover:decoration-2 focus-visible:ring-2 focus-visible:ring-white/70"
            @click="restart"
        >
            Start over
        </button>
        <button
            type="button"
            data-testid="tutorial-exit"
            class="shrink-0 rounded-sm bg-white px-2 py-0.5 font-medium whitespace-nowrap text-changelog outline-none focus-visible:ring-2 focus-visible:ring-white/70"
            @click="exit"
        >
            Exit tutorial
        </button>
    </div>
</template>
