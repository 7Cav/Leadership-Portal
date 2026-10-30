<script setup>
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { PhInfo } from '@phosphor-icons/vue';
import { toast } from 'vue-sonner';
import { onHttpException } from '@/lib/httpError';

// The changelog bar: the saturated --changelog blue, shaped like the act-as bar. Only a changelog editor sees it, after a redeploy nobody has
// written an entry for or dismissed. Adding an entry (an import counts) or dismissing it clears it for every editor.
const page = usePage();
const outdated = computed(() => page.props.changelogOutdated === true);
const dismissing = ref(false);

function dismiss() {
    dismissing.value = true;
    router.post(
        '/changelog/outdated/dismiss',
        {},
        {
            preserveScroll: true,
            onHttpException: onHttpException('The bar was not dismissed', (message) => toast.error(message)),
            onFinish: () => (dismissing.value = false),
        },
    );
}
</script>

<template>
    <div
        v-if="outdated"
        role="status"
        data-testid="changelog-outdated-banner"
        class="flex h-9 items-center gap-2.5 bg-changelog px-3 text-[13px] text-white sm:px-4"
    >
        <PhInfo class="size-4 shrink-0" weight="bold" aria-hidden="true" />
        <p class="min-w-0 flex-1 truncate">
            Changelog outdated<span class="hidden md:inline">. A new version is live with no entry for it.</span>
        </p>
        <Link
            href="/changelog?add=1"
            data-testid="changelog-outdated-add"
            class="shrink-0 rounded-sm font-medium whitespace-nowrap underline underline-offset-2 outline-none hover:decoration-2 focus-visible:ring-2 focus-visible:ring-white/70"
        >
            Add entry
        </Link>
        <button
            type="button"
            data-testid="changelog-outdated-dismiss"
            class="shrink-0 rounded-sm font-medium whitespace-nowrap underline underline-offset-2 outline-none hover:decoration-2 focus-visible:ring-2 focus-visible:ring-white/70 disabled:opacity-60"
            :disabled="dismissing"
            @click="dismiss"
        >
            Dismiss
        </button>
    </div>
</template>
