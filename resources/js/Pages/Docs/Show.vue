<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { PhArrowBendUpLeft, PhArrowLeft, PhArrowRight, PhArrowSquareOut, PhBookOpen, PhCaretDown, PhCaretRight, PhClock, PhRepeat, PhUsers } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import DocsLayout from '@/components/docs/DocsLayout.vue';
import GuideFeedback from '@/components/docs/GuideFeedback.vue';
import OnThisPage from '@/components/docs/OnThisPage.vue';
import StartHere from '@/components/docs/StartHere.vue';
import TickItOff from '@/components/docs/TickItOff.vue';
import { locate } from '@/Layouts/navigation';
import { audienceLabel, cameFrom, cavDate, openablePage } from '@/lib/docs';

// One guide (#410, agreed design): the contents on the left, the guide in the middle, and "On this page" with "Open
// in portal" on the right. The body is HTML the server rendered from markdown with raw HTML stripped (#408); it is
// shown as is and never compiled as a Vue template.
const props = defineProps({
    audience: { type: String, required: true },
    // `[{ key, title, guides: [{ title, summary, url, audience, weekly }] }]`
    contents: { type: Array, required: true },
    // `[{ id, label, url }]`: the Start here items for this viewer (#412).
    checklist: { type: Array, required: true },
    // Where "Was this helpful? No" sends the reader, or null.
    feedbackUrl: { type: String, default: null },
    // `[{ title, summary, url, section, headings, text }]`: the search index (#413).
    search: { type: Array, required: true },
    // `{ title, summary, url, section: { key, title }, audience, page, weekly, minutes, html, headings, checklist }`;
    // `checklist` is the guide's Start here item id when it is on this reader's list, else null.
    guide: { type: Object, required: true },
    previous: { type: Object, default: null },
    next: { type: Object, default: null },
    // `{ url, steps }` for the contents' "Getting started" entry, or null.
    tutorial: { type: Object, default: null },
});

const page = usePage();

// "You opened this from Rollcall": set by the "?" help (#411) as `?from=/rollcall`.
const from = computed(() => cameFrom(page.url));

const open = computed(() => {
    const href = openablePage(props.guide.page);

    return href ? { href, label: locate(href)?.item.label ?? 'in portal' } : null;
});
</script>

<template>
    <Head :title="`${guide.title} · Docs · 7Cav Leadership Portal`" />

    <DocsLayout :contents="contents" :current="guide.url" :search="search" :tutorial="tutorial">
        <article class="max-w-[680px]" data-testid="guide">
            <div v-if="from" class="mb-5 flex items-center gap-2.5 rounded-md bg-primary/12 px-3 py-2 text-[13px]" data-testid="came-from">
                <PhArrowBendUpLeft aria-hidden="true" />
                <span>You opened this from {{ from.label }}.</span>
                <Link :href="from.href" class="ml-auto font-semibold whitespace-nowrap text-gold-ink hover:underline">
                    Back to {{ from.label }}
                </Link>
            </div>

            <nav aria-label="Breadcrumb" class="mb-2 text-[13px] text-muted-foreground" data-testid="guide-breadcrumb">
                <ol class="flex flex-wrap items-center gap-1">
                    <li><Link href="/docs" class="hover:text-foreground hover:underline">Docs</Link></li>
                    <li aria-hidden="true"><PhCaretRight class="size-3" /></li>
                    <li class="font-medium text-gold-ink">{{ guide.section.title }}</li>
                </ol>
            </nav>
            <h1 class="mb-2.5 text-[32px] leading-[1.15] font-semibold tracking-tight text-balance">{{ guide.title }}</h1>
            <p class="mb-4 max-w-[60ch] text-[17px] leading-relaxed text-muted-foreground">{{ guide.summary }}</p>

            <div class="mb-6 flex flex-wrap items-center gap-2 text-xs font-medium text-muted-foreground">
                <span class="inline-flex h-5 items-center gap-1.5 rounded-sm bg-muted px-1.5" data-testid="guide-audience">
                    <PhUsers aria-hidden="true" />{{ audienceLabel(guide.audience) }}
                </span>
                <span v-if="guide.readingMinutes" class="inline-flex h-5 items-center gap-1.5 rounded-sm bg-muted px-1.5" data-testid="guide-reading">
                    <PhBookOpen aria-hidden="true" />{{ guide.readingMinutes }} min read
                </span>
                <span v-if="guide.minutes" class="inline-flex h-5 items-center gap-1.5 rounded-sm bg-muted px-1.5">
                    <PhClock aria-hidden="true" />Task takes about {{ guide.minutes }} {{ guide.minutes === 1 ? 'minute' : 'minutes' }}
                </span>
                <span v-if="guide.updated" class="inline-flex h-5 items-center gap-1.5 px-1.5">
                    Updated <time :datetime="guide.updated" class="font-mono">{{ cavDate(guide.updated) }}</time>
                </span>
                <span v-if="guide.weekly" class="inline-flex h-5 items-center gap-1.5 rounded-sm bg-muted px-1.5">
                    <PhRepeat aria-hidden="true" />Once a week
                </span>
                <!-- On a narrow screen "On this page" is hidden, so "Open in portal" sits here instead. -->
                <Button v-if="open" as-child size="sm" variant="outline" class="ml-auto xl:hidden">
                    <Link :href="open.href"><PhArrowSquareOut />Open {{ open.label }}</Link>
                </Button>
            </div>

            <!-- On a narrow screen "On this page" sits here, folded, since the right rail is below the guide. -->
            <details v-if="guide.headings.length > 1" class="group mb-6 rounded-lg border border-border bg-card xl:hidden" data-testid="on-this-page-mobile">
                <summary
                    class="flex cursor-pointer list-none items-center gap-2 rounded-lg px-3.5 py-2.5 text-[13px] font-semibold focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none [&::-webkit-details-marker]:hidden"
                >
                    On this page
                    <span class="font-normal text-muted-foreground">{{ guide.headings.length }} steps</span>
                    <PhCaretDown class="ml-auto transition-transform group-open:rotate-180" aria-hidden="true" />
                </summary>
                <ol class="border-t border-border px-3.5 py-2 text-[13.5px]">
                    <li v-for="(heading, index) in guide.headings" :key="heading.id">
                        <a :href="`#${heading.id}`" class="flex gap-2.5 rounded-md py-1.5 hover:text-foreground hover:underline">
                            <span class="w-4 flex-none text-right text-muted-foreground tabular-nums">{{ index + 1 }}</span>{{ heading.text }}
                        </a>
                    </li>
                </ol>
            </details>

            <!-- eslint-disable-next-line vue/no-v-html -- server-rendered markdown, raw HTML stripped (#408) -->
            <div class="docs-prose" data-testid="guide-body" v-html="guide.html" />

            <TickItOff v-if="guide.checklist" :id="guide.checklist" :checklist="checklist" />

            <GuideFeedback :url="feedbackUrl" />

            <nav v-if="previous || next" class="mt-10 grid grid-cols-1 gap-3 border-t border-border pt-5 sm:grid-cols-2" aria-label="More guides">
                <Link v-if="previous" :href="previous.url" rel="prev" class="rounded-lg border border-border bg-card px-3.5 py-3 hover:border-input">
                    <small class="flex items-center gap-1 text-xs text-muted-foreground"><PhArrowLeft aria-hidden="true" />Previous</small>
                    <span class="font-medium">{{ previous.title }}</span>
                </Link>
                <Link
                    v-if="next"
                    :href="next.url"
                    rel="next"
                    class="rounded-lg border border-border bg-card px-3.5 py-3 hover:border-input sm:col-start-2 sm:text-right"
                    data-testid="next-guide"
                >
                    <small class="flex items-center gap-1 text-xs text-muted-foreground sm:justify-end">Next<PhArrowRight aria-hidden="true" /></small>
                    <span class="font-medium">{{ next.title }}</span>
                </Link>
            </nav>
        </article>

        <template #rail>
            <StartHere :checklist="checklist" :audience="audience" :current="guide.url" />
            <OnThisPage :headings="guide.headings" :open="open" />
        </template>
    </DocsLayout>
</template>
