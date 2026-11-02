<script setup>
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { RANK_NAMES } from '@/lib/roster';
import { rankAvatarUrl } from '@/lib/rankAvatar';
import { cn } from '@/lib/utils';

// A trooper's 7Cav standard rank avatar, or their initials when the rank has none (a recruit, an unknown rank) or the
// image fails. Fixed size, so nothing moves when the image loads.
const props = defineProps({
    rank: { type: String, default: null },
    initials: { type: String, default: '' },
    size: { type: String, default: 'md' },
    // '' when the rank is written right next to it (the default); null to name the rank instead.
    alt: { type: String, default: '' },
    class: { type: [String, Array, Object], default: null },
    fallbackClass: { type: [String, Array, Object], default: null },
});

const SIZES = { sm: 'size-8 text-xs', md: 'size-10 text-xs', lg: 'size-12 text-lg' };

const src = computed(() => rankAvatarUrl(props.rank));
const altText = computed(() => props.alt ?? (RANK_NAMES[String(props.rank ?? '').toUpperCase()] || props.rank || ''));
</script>

<template>
    <Avatar :class="cn('flex-none border border-border bg-muted', SIZES[size] ?? SIZES.md, props.class)" data-rank-avatar>
        <!-- Reka shows the initials until the image loads, and if it fails. -->
        <AvatarImage v-if="src" :src="src" :alt="altText" data-testid="rank-avatar" />
        <AvatarFallback :class="cn('bg-muted font-semibold', fallbackClass)" aria-hidden="true">{{ initials }}</AvatarFallback>
    </Avatar>
</template>
