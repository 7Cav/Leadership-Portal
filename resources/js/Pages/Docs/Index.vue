<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { PhArrowRight, PhBookOpenText, PhCheckCircle, PhClipboardText, PhFileText, PhFlagPennant, PhUsersThree } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import DocsLayout from '@/components/docs/DocsLayout.vue';
import DocsSearchBox from '@/components/docs/DocsSearchBox.vue';
import StartHere from '@/components/docs/StartHere.vue';
import { useTutorialProgress } from '@/composables/useTutorialProgress';
import { cavDate, homeGroups, initialPath, roleLabel } from '@/lib/docs';

// Docs home (#410): the search first, then "New here?" into the tutorial, the guides that matter for one role, the
// guides changed last, and every guide the viewer may read grouped as Every week, When it comes up, For everyone and
// (Admin only) Admin. A group with nothing in it for this viewer is left out.
const props = defineProps({
    // `everyone`, `leader` or `admin`: which guides this viewer reads.
    audience: { type: String, required: true },
    // `[{ key, title, guides: [{ title, summary, url, audience, weekly }] }]`, only sections the viewer has a guide in.
    contents: { type: Array, required: true },
    // `[{ id, label, url }]`: the Start here items for this viewer (#412).
    checklist: { type: Array, required: true },
    feedbackUrl: { type: String, default: null },
    // `[{ title, summary, url, section, headings, text }]`: the search index (#413).
    search: { type: Array, required: true },
    // `[{ key, title, blurb, guides: [{ title, summary, url }] }]`: the role paths this viewer can follow.
    paths: { type: Array, default: () => [] },
    // `[{ title, summary, url, updated }]`: the guides changed last.
    recent: { type: Array, default: () => [] },
    // `{ url, steps: [{ id, title, url }] }`, or null when this viewer has no tutorial.
    tutorial: { type: Object, default: null },
});

const page = usePage();
const groups = computed(() => homeGroups(props.contents));

// The icon of a weekly task's row, by the section it is in.
const ICONS = { 'weekly-rollcall': PhClipboardText, 'weekly-report': PhFileText, 'your-troopers': PhUsersThree };

// The role path on show: the one this viewer picked last time (kept in this browser), else their audience's.
const PICKED = 'docs-path';
function readPicked() {
    try {
        return localStorage.getItem(PICKED);
    } catch {
        return null;
    }
}
const role = ref(initialPath(props.paths, props.audience, readPicked()));
const path = computed(() => props.paths.find((p) => p.key === role.value) ?? null);
watch(role, (key) => {
    try {
        localStorage.setItem(PICKED, key);
    } catch {
        // Storage is unavailable: the pick lasts for this visit only.
    }
});

const progress = useTutorialProgress(
    () => props.tutorial?.steps ?? [],
    () => ({ userId: page.props.auth?.user?.id ?? null, previewing: page.props.viewAs != null }),
);
// Where "Start the tutorial" goes: the first step not done yet, or the first step once all are done.
const resume = computed(() => {
    const steps = props.tutorial?.steps ?? [];

    return steps.find((step) => !progress.isDone(step.id)) ?? steps[0] ?? null;
});
const tutorialAction = computed(() => {
    if (progress.finished.value) return 'Go through it again';

    return progress.count.value ? `Continue with step ${props.tutorial.steps.indexOf(resume.value) + 1}` : 'Start the tutorial';
});

// Arrow keys move between the role buttons, as in any tab list.
function moveRole(event, index) {
    const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];
    if (!step) return;
    event.preventDefault();
    const next = props.paths[(index + step + props.paths.length) % props.paths.length];
    role.value = next.key;
    document.getElementById(`docs-path-${next.key}`)?.focus();
}
</script>

<template>
    <Head title="Docs · 7Cav Leadership Portal" />

    <DocsLayout :contents="contents" :search="search" :tutorial="tutorial">
        <div class="max-w-[720px]" data-testid="docs-home">
            <header class="mb-8">
                <p class="mb-2 inline-flex items-center gap-1.5 text-xs font-semibold text-gold-ink">
                    <PhBookOpenText weight="bold" aria-hidden="true" />Leadership Portal guides
                </p>
                <h1 class="mb-2 text-[32px] leading-[1.15] font-semibold tracking-tight text-balance">What do you need to do?</h1>
                <p class="mb-5 text-base text-muted-foreground">
                    Guides shown for your role: <b class="font-semibold text-foreground" data-testid="docs-role">{{ roleLabel(audience) }}</b>.
                </p>
                <DocsSearchBox placeholder="Search, for example: weekly report, guest, link player" class="h-12 max-w-[600px] text-[15px] shadow-xs" />
            </header>

            <section
                v-if="tutorial && resume"
                class="relative mb-10 overflow-hidden rounded-lg border border-primary/40 bg-primary/10 p-5 sm:p-6"
                aria-labelledby="docs-tutorial-title"
                data-testid="docs-tutorial-cta"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                    <div class="min-w-0 flex-1">
                        <p class="mb-1 text-xs font-semibold text-gold-ink">New here?</p>
                        <h2 id="docs-tutorial-title" class="mb-1 text-lg font-semibold tracking-tight">Getting started tutorial</h2>
                        <p class="text-sm text-muted-foreground">
                            {{ tutorial.steps.length }} short steps, from signing in to sending your weekly report. Each one has a page to try it on.
                        </p>
                        <div class="mt-3 flex items-center gap-2.5" data-testid="docs-tutorial-progress">
                            <div
                                class="h-1.5 w-36 overflow-hidden rounded-full bg-background/70"
                                role="progressbar"
                                aria-label="Tutorial progress"
                                :aria-valuenow="progress.count.value"
                                aria-valuemin="0"
                                :aria-valuemax="progress.total.value"
                            >
                                <div class="h-full rounded-full bg-primary transition-[width]" :style="{ width: `${(progress.count.value / progress.total.value) * 100}%` }" />
                            </div>
                            <span class="text-xs text-muted-foreground tabular-nums">{{ progress.count.value }} of {{ progress.total.value }} done</span>
                        </div>
                    </div>
                    <Button as-child size="lg" class="self-start sm:self-center">
                        <Link :href="resume.url" data-testid="docs-tutorial-start">
                            <component :is="progress.finished.value ? PhCheckCircle : PhFlagPennant" weight="bold" />{{ tutorialAction }}
                        </Link>
                    </Button>
                </div>
            </section>

            <section v-if="paths.length" class="mb-10" aria-labelledby="docs-paths-title" data-testid="docs-paths">
                <h2 id="docs-paths-title" class="mb-1 text-xl font-semibold tracking-tight">Guides for your role</h2>
                <p class="mb-4 text-sm text-muted-foreground">Pick the role closest to yours. The guides are in the order to read them.</p>

                <div role="tablist" aria-label="Roles" class="mb-4 flex flex-wrap gap-2">
                    <button
                        v-for="(option, index) in paths"
                        :id="`docs-path-${option.key}`"
                        :key="option.key"
                        type="button"
                        role="tab"
                        :aria-selected="role === option.key"
                        :tabindex="role === option.key ? 0 : -1"
                        aria-controls="docs-path-panel"
                        class="inline-flex h-9 items-center rounded-full border px-3.5 text-[13px] font-medium transition-colors focus-visible:ring-3 focus-visible:ring-ring/50 focus-visible:outline-none"
                        :class="
                            role === option.key
                                ? 'border-foreground bg-foreground text-background'
                                : 'border-border bg-card text-muted-foreground hover:border-input hover:text-foreground'
                        "
                        :data-testid="`docs-path-${option.key}`"
                        @click="role = option.key"
                        @keydown="moveRole($event, index)"
                    >
                        {{ option.title }}
                    </button>
                </div>

                <div v-if="path" id="docs-path-panel" role="tabpanel" :aria-labelledby="`docs-path-${path.key}`" data-testid="docs-path-panel">
                    <p class="mb-3 text-sm">{{ path.blurb }}</p>
                    <ol class="divide-y divide-border overflow-hidden rounded-lg border border-border bg-card">
                        <li v-for="(guide, index) in path.guides" :key="guide.url">
                            <Link :href="guide.url" class="group flex items-center gap-3.5 px-4 py-3 hover:bg-accent focus-visible:bg-accent">
                                <span
                                    class="grid size-7 flex-none place-items-center rounded-full border border-border text-xs font-semibold text-muted-foreground tabular-nums group-hover:border-primary group-hover:bg-primary group-hover:text-primary-foreground"
                                    aria-hidden="true"
                                    >{{ index + 1 }}</span
                                >
                                <span class="min-w-0">
                                    <span class="block font-medium">{{ guide.title }}</span>
                                    <span class="block text-[13px] leading-snug text-muted-foreground">{{ guide.summary }}</span>
                                </span>
                                <PhArrowRight class="ml-auto flex-none text-muted-foreground transition-transform group-hover:translate-x-0.5" aria-hidden="true" />
                            </Link>
                        </li>
                    </ol>
                </div>
            </section>

            <section v-if="recent.length" class="mb-10" aria-labelledby="docs-recent-title" data-testid="docs-recent">
                <h2 id="docs-recent-title" class="mb-3 text-xl font-semibold tracking-tight">Recently updated</h2>
                <ul class="space-y-0.5">
                    <li v-for="guide in recent" :key="guide.url">
                        <Link :href="guide.url" class="flex items-baseline gap-3 rounded-md px-2 py-1.5 text-sm hover:bg-accent">
                            <span class="min-w-0 truncate font-medium">{{ guide.title }}</span>
                            <time :datetime="guide.updated" class="ml-auto flex-none font-mono text-xs text-muted-foreground">{{ cavDate(guide.updated) }}</time>
                        </Link>
                    </li>
                </ul>
            </section>

            <section aria-labelledby="docs-all-title">
                <h2 id="docs-all-title" class="mb-4 text-xl font-semibold tracking-tight">All guides</h2>
                <div v-for="group in groups" :key="group.id" class="mb-6" :data-testid="`docs-group-${group.id}`">
                    <h3 class="mb-2 text-[13px] font-semibold text-muted-foreground">{{ group.title }}</h3>
                    <div v-if="group.rows" class="rounded-lg border border-border bg-card p-1.5">
                        <Link
                            v-for="guide in group.guides"
                            :key="guide.url"
                            :href="guide.url"
                            class="flex items-center gap-3 rounded-md px-3 py-2.5 hover:bg-accent"
                        >
                            <component :is="ICONS[guide.section] ?? PhBookOpenText" class="size-5 flex-none text-[var(--nav-active-icon)]" aria-hidden="true" />
                            <span class="min-w-0">
                                <span class="block font-medium">{{ guide.title }}</span>
                                <small class="block text-[13px] leading-snug text-muted-foreground">{{ guide.summary }}</small>
                            </span>
                            <PhArrowRight class="ml-auto flex-none text-muted-foreground" aria-hidden="true" />
                        </Link>
                    </div>
                    <ul v-else class="gap-x-5 sm:columns-2">
                        <li v-for="guide in group.guides" :key="guide.url" class="break-inside-avoid">
                            <Link :href="guide.url" class="block rounded-md px-2 py-1.5 text-sm text-muted-foreground hover:bg-accent hover:text-foreground">
                                {{ guide.title }}
                            </Link>
                        </li>
                    </ul>
                </div>

                <p v-if="!groups.length" class="text-sm text-muted-foreground">No guides have been written for your role yet.</p>
            </section>
        </div>

        <template v-if="checklist.length" #rail>
            <StartHere :checklist="checklist" :audience="audience" />
        </template>
    </DocsLayout>
</template>
