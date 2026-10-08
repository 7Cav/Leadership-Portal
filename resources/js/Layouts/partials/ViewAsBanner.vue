<script setup>
import { ref } from 'vue';
import { PhEye } from '@phosphor-icons/vue';
import { useViewAs } from '@/composables/useViewAs';

// The view-as banner (Story 1.13, binding design: mockups/v2/shell-f.html `#viewAsBanner`). It
// rides in the sticky top bar, above the header, so it stays on screen however far the page
// scrolls: a leader must never forget they are looking through someone else's eyes.
const { viewAs, subject, exit } = useViewAs();
const leaving = ref(false);

function leave() {
    leaving.value = true;
    exit({ onFinish: () => (leaving.value = false) });
}
</script>

<template>
    <div
        v-if="viewAs"
        role="status"
        data-testid="view-as-banner"
        class="flex h-9 items-center gap-2.5 bg-primary px-3 text-[13px] text-primary-foreground sm:px-4"
    >
        <PhEye class="size-4 shrink-0" weight="bold" aria-hidden="true" />
        <p class="min-w-0 flex-1 truncate">
            Viewing as <strong class="font-semibold">{{ subject }}</strong>
            <template v-if="viewAs.kind === 'user' && viewAs.designation">
                <span class="font-mono text-[12.5px]"> · {{ viewAs.designation }}</span>
            </template>
            <template v-else-if="viewAs.kind === 'unit' && viewAs.unitName !== viewAs.designation">
                <span class="hidden sm:inline"> ({{ viewAs.unitName }})</span>
            </template>
            <span class="hidden md:inline">. Read-only preview of their scope.</span>
        </p>
        <button
            type="button"
            data-testid="view-as-exit"
            class="shrink-0 rounded-sm font-medium whitespace-nowrap underline underline-offset-2 outline-none hover:decoration-2 focus-visible:ring-2 focus-visible:ring-primary-foreground/70 disabled:opacity-60"
            :disabled="leaving"
            @click="leave"
        >
            Exit view as
        </button>
    </div>
</template>
