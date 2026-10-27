<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { PhArrowBendUpLeft, PhArrowSquareOut, PhBookOpenText, PhClock, PhRepeat, PhUsers } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import DocsLayout from '@/components/docs/DocsLayout.vue';
import GuideFeedback from '@/components/docs/GuideFeedback.vue';
import OnThisPage from '@/components/docs/OnThisPage.vue';
import { locate } from '@/Layouts/navigation';
import { audienceLabel, cameFrom, openablePage } from '@/lib/docs';

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
    // `{ title, summary, url, section: { key, title }, audience, page, weekly, minutes, html, headings, checklist }`
    guide: { type: Object, required: true },
    previous: { type: Object, default: null },
    next: { type: Object, default: null },
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

    <DocsLayout :contents="contents" :current="guide.url">
        <article class="max-w-[680px]" data-testid="guide">
            <div v-if="from" class="mb-5 flex items-center gap-2.5 rounded-md bg-primary/12 px-3 py-2 text-[13px]" data-testid="came-from">
                <PhArrowBendUpLeft aria-hidden="true" />
                <span>You opened this from {{ from.label }}.</span>
                <Link :href="from.href" class="ml-auto font-semibold whitespace-nowrap text-gold-ink hover:underline">
                    Back to {{ from.label }}
                </Link>
            </div>

            <span class="inline-flex h-5 items-center gap-1.5 rounded-sm bg-primary/18 px-1.5 text-xs font-medium text-gold-ink">
                <PhBookOpenText aria-hidden="true" />{{ guide.section.title }}
            </span>
            <h1 class="mt-1.5 mb-2.5 text-[30px] leading-tight font-semibold tracking-tight">{{ guide.title }}</h1>
            <p class="mb-4 max-w-[60ch] text-base text-muted-foreground">{{ guide.summary }}</p>

            <div class="mb-6 flex flex-wrap items-center gap-2 text-xs font-medium text-muted-foreground">
                <span class="inline-flex h-5 items-center gap-1.5 rounded-sm bg-muted px-1.5" data-testid="guide-audience">
                    <PhUsers aria-hidden="true" />{{ audienceLabel(guide.audience) }}
                </span>
                <span v-if="guide.minutes" class="inline-flex h-5 items-center gap-1.5 rounded-sm bg-muted px-1.5">
                    <PhClock aria-hidden="true" />About {{ guide.minutes }} {{ guide.minutes === 1 ? 'minute' : 'minutes' }}
                </span>
                <span v-if="guide.weekly" class="inline-flex h-5 items-center gap-1.5 rounded-sm bg-muted px-1.5">
                    <PhRepeat aria-hidden="true" />Once a week
                </span>
                <!-- On a narrow screen "On this page" is hidden, so "Open in portal" sits here instead. -->
                <Button v-if="open" as-child size="sm" variant="outline" class="ml-auto xl:hidden">
                    <Link :href="open.href"><PhArrowSquareOut />Open {{ open.label }}</Link>
                </Button>
            </div>

            <!-- eslint-disable-next-line vue/no-v-html -- server-rendered markdown, raw HTML stripped (#408) -->
            <div class="docs-prose" data-testid="guide-body" v-html="guide.html" />

            <GuideFeedback :url="feedbackUrl" />

            <nav v-if="previous || next" class="mt-10 grid grid-cols-1 gap-3 border-t border-border pt-5 sm:grid-cols-2" aria-label="More guides">
                <Link v-if="previous" :href="previous.url" rel="prev" class="rounded-lg border border-border bg-card px-3.5 py-3 hover:border-input">
                    <small class="block text-xs text-muted-foreground">Previous</small>{{ previous.title }}
                </Link>
                <Link
                    v-if="next"
                    :href="next.url"
                    rel="next"
                    class="rounded-lg border border-border bg-card px-3.5 py-3 hover:border-input sm:col-start-2 sm:text-right"
                    data-testid="next-guide"
                >
                    <small class="block text-xs text-muted-foreground">Next</small>{{ next.title }}
                </Link>
            </nav>
        </article>

        <template #rail>
            <OnThisPage :headings="guide.headings" :open="open" />
        </template>
    </DocsLayout>
</template>
