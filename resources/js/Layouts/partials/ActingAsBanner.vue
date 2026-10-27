<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { PhWarningOctagon } from '@phosphor-icons/vue';

// The act-as bar (#379): red, not the view-as gold, because here every click is real. An Admin is acting with
// someone else's permissions and everything they change is recorded as them acting for that person. It rides in the
// sticky top bar with the header, so it never scrolls away.
const page = usePage();
const actingAs = computed(() => page.props.actingAs ?? null);
const stopping = ref(false);

function stop() {
    stopping.value = true;
    router.delete('/act-as', { onFinish: () => (stopping.value = false) });
}
</script>

<template>
    <div
        v-if="actingAs"
        role="status"
        data-testid="acting-as-banner"
        class="flex h-9 items-center gap-2.5 bg-destructive px-3 text-[13px] text-white sm:px-4 dark:bg-[hsl(3_68%_42%)]"
    >
        <PhWarningOctagon class="size-4 shrink-0" weight="bold" aria-hidden="true" />
        <p class="min-w-0 flex-1 truncate">
            Acting as <strong class="font-semibold">{{ actingAs.label }}</strong>
            <span class="hidden md:inline">. Changes are real and recorded as {{ actingAs.admin }} acting for them.</span>
        </p>
        <button
            type="button"
            data-testid="acting-as-stop"
            class="shrink-0 rounded-sm font-medium whitespace-nowrap underline underline-offset-2 outline-none hover:decoration-2 focus-visible:ring-2 focus-visible:ring-white/70 disabled:opacity-60"
            :disabled="stopping"
            @click="stop"
        >
            Stop acting
        </button>
    </div>
</template>
