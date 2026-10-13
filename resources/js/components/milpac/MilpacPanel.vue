<script setup>
import { Card, CardAction, CardHeader } from '@/components/ui/card';

// One panel of the milpac (courses 6.4, and the awards, service record and quick actions that follow): a Card
// with a titled header and a body, so every panel shares the spacing and type of the Roster and Reports screens.
// The optional `count` slot sits at the header's right edge. The title is an h2 (the page's h1 is the trooper).
defineProps({
    title: { type: String, required: true },
});
</script>

<template>
    <Card class="gap-0 py-0" data-slot="milpac-panel">
        <CardHeader class="items-center border-b border-border px-4 py-3 [.border-b]:pb-3">
            <h2 data-slot="card-title" class="text-[15px] leading-none font-semibold">{{ title }}</h2>
            <CardAction v-if="$slots.count" class="row-span-1 self-center"><slot name="count" /></CardAction>
        </CardHeader>
        <slot />
    </Card>
</template>
