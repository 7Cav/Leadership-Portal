<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import WarningBanner from '@/components/WarningBanner.vue';

// The top-of-page warning for troopers with no identity linked for the game their unit plays. A missing identity breaks
// the capture, presence, automatic make-ups and the staff report's server check, so this is loud and not dismissible:
// it goes away once the identities are linked. Shared by Home, the roll call and the roster.
const props = defineProps({
    // `[{ game, count, troopers: [{ id, name }] }]`, one entry per game; empty when nobody is missing one.
    groups: { type: Array, default: () => [] },
    // "of your troopers" on Home; the roll call and roster speak of the page's own list.
    own: { type: Boolean, default: true },
});

const INLINE = 3;
const expanded = ref({});

function shown(group) {
    return expanded.value[group.game] ? group.troopers : group.troopers.slice(0, INLINE);
}

const total = computed(() => props.groups.reduce((sum, group) => sum + group.count, 0));

function headline(group) {
    const who = group.count === 1 ? (props.own ? 'of your troopers has' : 'trooper has') : props.own ? 'of your troopers have' : 'troopers have';

    return `${group.count} ${who} no ${group.game} identity linked`;
}
</script>

<template>
    <WarningBanner v-if="groups.length" data-testid="missing-identities-banner">
        <div v-for="group in groups" :key="group.game" data-testid="missing-identity-group">
            <p class="font-semibold">{{ headline(group) }}</p>
            <p class="text-muted-foreground">
                {{ shown(group).map((t) => t.name).join(', ') }}
                <button
                    v-if="group.troopers.length > INLINE"
                    type="button"
                    class="ml-1 font-medium text-foreground underline underline-offset-2"
                    :aria-expanded="!!expanded[group.game]"
                    data-testid="missing-identity-toggle"
                    @click="expanded[group.game] = !expanded[group.game]"
                >
                    {{ expanded[group.game] ? 'Show fewer' : `Show all ${group.troopers.length}` }}
                </button>
            </p>
        </div>
        <p>The game server can't see {{ total === 1 ? 'this trooper' : 'these troopers' }}, so their practice attendance and make-ups won't be recorded automatically.</p>
        <template #action>
            <Button size="sm" variant="outline" class="w-full sm:w-auto" as-child>
                <Link href="/game-identities" data-testid="missing-identities-link">{{ total === 1 ? 'Link it on Game identities' : 'Link them on Game identities' }}</Link>
            </Button>
        </template>
    </WarningBanner>
</template>
