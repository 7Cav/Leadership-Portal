<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { PhArrowSquareOut } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';

// "On this page" (#410): the guide's steps, the one in view highlighted, and "Open in portal" to the screen the guide
// explains when it is a fixed path. Hidden on narrow screens by the page.
const props = defineProps({
    // `[{ id, text }]`: the guide's level-2 headings.
    headings: { type: Array, required: true },
    // `{ href, label }` for "Open in portal", or null.
    open: { type: Object, default: null },
});

const active = ref(props.headings[0]?.id ?? null);
let observer = null;

// The step in view is the last heading that has scrolled above the upper third of the screen.
function watchHeadings() {
    observer?.disconnect();
    if (typeof IntersectionObserver === 'undefined') return;

    const seen = new Map();
    observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => seen.set(entry.target.id, entry.boundingClientRect.top));
            const passed = props.headings.filter((heading) => (seen.get(heading.id) ?? Infinity) < window.innerHeight / 3);
            active.value = (passed.at(-1) ?? props.headings[0])?.id ?? null;
        },
        { threshold: [0, 1], rootMargin: '0px 0px -66% 0px' },
    );
    props.headings.forEach((heading) => {
        const element = document.getElementById(heading.id);
        if (element) observer.observe(element);
    });
}

onMounted(watchHeadings);
watch(
    () => props.headings,
    () => {
        active.value = props.headings[0]?.id ?? null;
        requestAnimationFrame(watchHeadings);
    },
);
onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <nav aria-label="On this page" data-testid="on-this-page" class="hidden text-[13px] xl:block">
        <template v-if="headings.length">
            <p class="mb-2 font-semibold">On this page</p>
            <a
                v-for="heading in headings"
                :key="heading.id"
                :href="`#${heading.id}`"
                class="block border-l-2 py-1 pl-3 hover:border-primary hover:text-foreground"
                :class="active === heading.id ? 'border-primary text-foreground' : 'border-border text-muted-foreground'"
                :aria-current="active === heading.id ? 'location' : undefined"
                @click="active = heading.id"
            >
                {{ heading.text }}
            </a>
        </template>
        <Button v-if="open" as-child class="mt-4 w-full">
            <Link :href="open.href" data-testid="open-in-portal">
                <PhArrowSquareOut weight="bold" />
                Open {{ open.label }}
            </Link>
        </Button>
    </nav>
</template>
