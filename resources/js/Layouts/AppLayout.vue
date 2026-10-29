<script setup>
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { PhMagnifyingGlass, PhMoon, PhQuestion, PhSun, PhUserSwitch } from '@phosphor-icons/vue';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { SidebarInset, SidebarProvider, SidebarTrigger } from '@/components/ui/sidebar';
import { toast } from 'vue-sonner';
import { Toaster } from '@/components/ui/sonner';
import { handleSlash, slashClaimed } from '@/composables/useSlashShortcut';
import { useTheme } from '@/composables/useTheme';
import { useTrooperSearch } from '@/composables/useTrooperSearch';
import { useViewAs } from '@/composables/useViewAs';
import { scopeLabel } from '@/lib/scopeSwitch';
import { isActive, locate } from './navigation';
import 'vue-sonner/style.css';
import AppSidebar from './partials/AppSidebar.vue';
import KeyboardShortcuts from './partials/KeyboardShortcuts.vue';
import TrooperSearch from './partials/TrooperSearch.vue';
import ActAsPicker from './partials/ActAsPicker.vue';
import ActingAsBanner from './partials/ActingAsBanner.vue';
import ViewAsBanner from './partials/ViewAsBanner.vue';
import ChangelogOutdatedBanner from './partials/ChangelogOutdatedBanner.vue';
import ViewAsPicker from './partials/ViewAsPicker.vue';

// The persistent app shell (Story 1.15, binding design: mockups/v2/shell-f.html). Set as the
// default layout for every page outside Auth/ in app.js, so it survives Inertia visits: the
// sidebar's collapsed state and open menus are not rebuilt on every navigation.
const page = usePage();
const { theme, toggleTheme, syncFromDocument } = useTheme();
const { active: viewingAs, openPicker } = useViewAs();
const { openSearch } = useTrooperSearch();
const actingAs = computed(() => page.props.actingAs != null);

// The trail starts at the top of the viewer's scope as this request sees it (#426): 1/1/A/1-7 for a section leader,
// 7CAV for an Admin. Then the page's own crumbs, from the nav it lives under.
const scopeCrumb = computed(() => scopeLabel(page.props.scopeTop));

const trail = computed(() => {
    const found = locate(page.url);
    if (!found) return [];

    const trail = found.group.crumb === false ? [found.item.label] : [found.group.label, found.item.label];

    // A page nested under a nav item names its own last crumb (the trooper's name) in a `crumb` prop.
    return found.nested && page.props.crumb ? [...trail, page.props.crumb] : trail;
});

// The "?" help (#411): the server opens the viewer's guide for this page, or docs home when none matches.
const docsOn = computed(() => page.props.features?.docs === true);
const helpHref = computed(() => `/docs/help?from=${encodeURIComponent(page.url)}`);
const onDocs = computed(() => isActive(page.url, '/docs'));

// A one-line outcome the server flashed for this render (`flash.notice`), such as where a leave sync request went.
// Watched by the whole object, which is new on every response, so the same notice twice in a row still shows twice.
watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.notice) toast(flash.notice);
    },
    { immediate: true },
);

// app.blade.php themes <html> before this module loads; pick that up so the toggle starts right.
onMounted(syncFromDocument);

// `/` opens the trooper search from any page, unless the page claims it for a search of its own (the roster's filter).
const onKeydown = (event) => handleSlash(event, openSearch);
onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <a
        href="#main"
        class="sr-only z-50 rounded-md border bg-card px-3 py-1.5 text-[13px] font-medium focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
    >
        Skip to content
    </a>

    <SidebarProvider>
        <AppSidebar />

        <!-- `--shell-top` is the sticky bar's height (header h-12, plus the h-9 view-as banner when it
             shows). A page's own sticky parts offset from it so they stop below the bar, not under it. -->
        <SidebarInset class="min-w-0" :style="{ '--shell-top': `${3 + (viewingAs ? 2.25 : 0) + (actingAs ? 2.25 : 0) + (page.props.changelogOutdated ? 2.25 : 0)}rem` }">
            <!-- The banner shares the sticky bar with the header, so a view-as preview stays in sight. -->
            <div class="sticky top-0 z-30">
                <ActingAsBanner />
                <ViewAsBanner />
                <ChangelogOutdatedBanner />
                <header class="flex h-12 items-center gap-2 border-b border-border bg-background/90 px-3 backdrop-blur sm:px-4">
                    <SidebarTrigger aria-label="Toggle sidebar" />
                    <Separator orientation="vertical" class="mx-1 data-[orientation=vertical]:h-4" />

                    <Breadcrumb class="min-w-0">
                        <BreadcrumbList class="flex-nowrap gap-1.5 text-[13px] whitespace-nowrap sm:gap-1.5">
                            <BreadcrumbItem class="hidden sm:inline-flex" data-testid="breadcrumb-scope">{{ scopeCrumb }}</BreadcrumbItem>
                            <template v-for="(crumb, index) in trail" :key="crumb">
                                <BreadcrumbSeparator class="hidden sm:block" />
                                <!-- On a phone only the current page shows; its trail and separators collapse. -->
                                <BreadcrumbItem :class="index < trail.length - 1 ? 'hidden sm:inline-flex' : 'min-w-0'">
                                    <BreadcrumbPage v-if="index === trail.length - 1" class="truncate font-medium" data-testid="breadcrumb-current">
                                        {{ crumb }}
                                    </BreadcrumbPage>
                                    <template v-else>{{ crumb }}</template>
                                </BreadcrumbItem>
                            </template>
                        </BreadcrumbList>
                    </Breadcrumb>

                    <div class="ml-auto flex items-center gap-1.5">
                        <Button
                            variant="ghost"
                            size="sm"
                            class="text-muted-foreground"
                            data-testid="trooper-search-trigger"
                            aria-haspopup="dialog"
                            :aria-keyshortcuts="slashClaimed ? undefined : '/'"
                            @click="openSearch"
                        >
                            <PhMagnifyingGlass />
                            <span class="hidden sm:inline">Find trooper</span>
                            <span class="sr-only sm:hidden">Find trooper</span>
                            <kbd
                                v-if="!slashClaimed"
                                class="hidden rounded-sm border border-border bg-muted px-1 font-mono text-[11px] leading-4 text-muted-foreground sm:inline"
                                aria-hidden="true"
                                >/</kbd
                            >
                        </Button>
                        <!-- Help (#411): between Find trooper and View as, only while the docs are on. -->
                        <Button
                            v-if="docsOn"
                            as-child
                            variant="ghost"
                            size="sm"
                            :class="onDocs ? 'bg-accent text-accent-foreground' : 'text-muted-foreground'"
                        >
                            <Link :href="helpHref" data-testid="help-trigger" title="Help for this page" aria-label="Help for this page">
                                <PhQuestion weight="bold" />
                                <span class="hidden sm:inline" aria-hidden="true">Help</span>
                            </Link>
                        </Button>
                        <!-- View as (Story 1.13): in the top bar on every page, as the issue asks (UX-DR2). -->
                        <Button
                            variant="ghost"
                            size="sm"
                            data-testid="view-as-trigger"
                            aria-haspopup="dialog"
                            :data-active="viewingAs"
                            :class="viewingAs ? 'bg-accent text-accent-foreground' : 'text-muted-foreground'"
                            @click="openPicker"
                        >
                            <PhUserSwitch />
                            <span class="hidden sm:inline">View as</span>
                            <span class="sr-only sm:hidden">View as</span>
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            data-testid="theme-toggle"
                            :aria-label="theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'"
                            @click="toggleTheme"
                        >
                            <PhSun v-if="theme === 'dark'" />
                            <PhMoon v-else />
                        </Button>
                    </div>
                </header>
            </div>

            <!-- The docs sit against the sidebar rather than centred: their own contents column is a second sidebar,
                 and centring the page left a wide gap between the two on large screens. -->
            <main
                id="main"
                tabindex="-1"
                class="w-full max-w-[1440px] flex-1 px-4 py-5 outline-none sm:px-6"
                :class="onDocs ? '' : 'mx-auto'"
                :data-docs-width="onDocs ? 'left' : null"
            >
                <slot />
            </main>
        </SidebarInset>
    </SidebarProvider>

    <ViewAsPicker />
    <ActAsPicker />
    <TrooperSearch />

    <!-- `?` on any page lists every keyboard shortcut (lib/shortcuts.js). -->
    <KeyboardShortcuts />

    <!-- The one toast region for every page, following the theme toggle: a page calls vue-sonner's
         `toast()`, never mounts its own. -->
    <Toaster :theme="theme" position="bottom-right" close-button />
</template>
