<script setup>
import { computed } from 'vue';
import { PhCircleNotch, PhPlugsConnected, PhWarning } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { forumDateTime } from '@/lib/forumDate';

// Why a practice has no server data (Story 7.9, mockups/v2/capture-review.html variant C, "Server data:
// Unavailable"): the game server could not be read (with when, AD-20), or telemetry is not set up for
// the game. Either way nothing was pre-filled and the leader marks by hand; "Try again" asks the portal
// to read the server once more. Not dismissible: it stays until a capture succeeds.
const props = defineProps({
    // `{ state: 'failed' | 'not_configured', platformLabel, lastAttemptAt, canRetry }`.
    telemetry: { type: Object, required: true },
    // A "Try again" is in flight.
    retrying: { type: Boolean, default: false },
    // False in a read-only (view-as) preview.
    editable: { type: Boolean, default: true },
});

defineEmits(['retry']);

const failed = computed(() => props.telemetry.state === 'failed');
const lastTried = computed(() => (props.telemetry.lastAttemptAt ? `${forumDateTime(props.telemetry.lastAttemptAt)} UTC` : null));
</script>

<template>
    <div
        role="status"
        class="mt-3 flex flex-wrap items-start gap-x-2.5 gap-y-2 rounded-lg border border-warning/35 bg-warning/8 px-3.5 py-2.5 text-[13px]"
        data-testid="telemetry-banner"
        :data-state="telemetry.state"
    >
        <component :is="failed ? PhWarning : PhPlugsConnected" class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
        <p v-if="failed" class="min-w-[14rem] flex-1">
            Game server could not be read<template v-if="lastTried"> (last tried {{ lastTried }})</template>. Nothing was pre-filled. Mark attendance by hand.
        </p>
        <p v-else class="min-w-[14rem] flex-1">
            Telemetry is not configured<template v-if="telemetry.platformLabel"> for {{ telemetry.platformLabel }}</template>, so nothing was pre-filled. Mark attendance by hand.
        </p>
        <Button v-if="telemetry.canRetry" variant="outline" size="sm" :disabled="!editable || retrying" data-testid="telemetry-retry" @click="$emit('retry')">
            <PhCircleNotch v-if="retrying" class="animate-spin" aria-hidden="true" />{{ retrying ? 'Trying...' : 'Try again' }}
        </Button>
    </div>
</template>
