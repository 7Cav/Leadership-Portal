<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { PhCaretRight, PhCheckCircle, PhFlagPennant } from '@phosphor-icons/vue';
import { useTutorialProgress } from '@/composables/useTutorialProgress';

// The docs contents (#410): docs home, then each section the viewer has a guide in as a collapsible group. The current
// guide is highlighted and its section starts open; on docs home every section starts open.
const props = defineProps({
    // `[{ key, title, guides: [{ title, url }] }]`, already filtered to the viewer's audience.
    contents: { type: Array, required: true },
    // The open guide's URL, or null on docs home.
    current: { type: String, default: null },
    // `{ url, steps: [{ id, title, url }] }`, or null when this viewer has no tutorial.
    tutorial: { type: Object, default: null },
});

const page = usePage();
const progress = useTutorialProgress(
    () => props.tutorial?.steps ?? [],
    () => ({ userId: page.props.auth?.user?.id ?? null, previewing: page.props.viewAs != null }),
);
const inTutorial = () => props.tutorial?.steps.some((step) => step.url === props.current) ?? false;

const holdsCurrent = (section) => section.guides.some((guide) => guide.url === props.current);
</script>

<template>
    <nav aria-label="Documentation" data-testid="docs-tree" class="text-[13px]">
        <slot name="top" />
        <Link
            href="/docs"
            class="mb-2 block rounded-md px-2 py-1.5 font-semibold hover:bg-accent"
            :class="current === null ? 'bg-primary/15' : ''"
            :aria-current="current === null ? 'page' : undefined"
        >
            Docs home
        </Link>
        <Link
            v-if="tutorial"
            :href="tutorial.url"
            class="mb-3 flex items-center gap-2 rounded-md px-2 py-1.5 font-semibold hover:bg-accent"
            :class="inTutorial() ? 'bg-primary/15' : ''"
            data-testid="docs-tree-tutorial"
        >
            <component :is="progress.finished.value ? PhCheckCircle : PhFlagPennant" class="size-4 text-[var(--nav-active-icon)]" aria-hidden="true" />
            Getting started
            <span class="ml-auto text-xs font-normal text-muted-foreground tabular-nums">{{ progress.count.value }} of {{ progress.total.value }}</span>
        </Link>
        <details v-for="section in contents" :key="section.key" class="group mb-1.5" :open="current === null || holdsCurrent(section)">
            <summary
                class="flex cursor-pointer list-none items-center gap-2 rounded-md px-2 py-1.5 font-semibold hover:bg-accent [&::-webkit-details-marker]:hidden"
            >
                <PhCaretRight weight="bold" class="size-3 text-muted-foreground transition-transform group-open:rotate-90" aria-hidden="true" />
                {{ section.title }}
            </summary>
            <ul class="mt-0.5 mb-1.5 ml-[15px] border-l border-border pl-2.5">
                <li v-for="guide in section.guides" :key="guide.url">
                    <Link
                        :href="guide.url"
                        class="block rounded-md px-2 py-1.5 hover:bg-accent hover:text-foreground"
                        :class="guide.url === current ? 'bg-primary/15 font-semibold text-foreground' : 'text-muted-foreground'"
                        :aria-current="guide.url === current ? 'page' : undefined"
                    >
                        {{ guide.title }}
                    </Link>
                </li>
            </ul>
        </details>
    </nav>
</template>
