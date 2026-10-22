<script setup>
import { computed, onMounted } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { PhMoon, PhSun, PhUserSwitch } from '@phosphor-icons/vue';
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
import { Toaster } from '@/components/ui/sonner';
import { useTheme } from '@/composables/useTheme';
import { useViewAs } from '@/composables/useViewAs';
import { locate } from './navigation';
import 'vue-sonner/style.css';
import AppSidebar from './partials/AppSidebar.vue';
import KeyboardShortcuts from './partials/KeyboardShortcuts.vue';
import ViewAsBanner from './partials/ViewAsBanner.vue';
import ViewAsPicker from './partials/ViewAsPicker.vue';

// The persistent app shell (Story 1.15, binding design: mockups/v2/shell-f.html). Set as the
// default layout for every page outside Auth/ in app.js, so it survives Inertia visits: the
// sidebar's collapsed state and open menus are not rebuilt on every navigation.
const page = usePage();
const { theme, toggleTheme, syncFromDocument } = useTheme();
const { active: viewingAs, openPicker } = useViewAs();

// The page's own breadcrumb trail, from the nav it lives under. Scope crumbs (battalion,
// company) join once unit scopes exist; for now the regiment is the only scope.
const trail = computed(() => {
    const found = locate(page.url);
    if (!found) return [];

    const trail = found.group.id === 'leadership' ? [found.item.label] : [found.group.label, found.item.label];

    // A page nested under a nav item names its own last crumb (the trooper's name) in a `crumb` prop.
    return found.nested && page.props.crumb ? [...trail, page.props.crumb] : trail;
});

// app.blade.php themes <html> before this module loads; pick that up so the toggle starts right.
onMounted(syncFromDocument);
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
        <SidebarInset class="min-w-0" :style="{ '--shell-top': viewingAs ? '5.25rem' : '3rem' }">
            <!-- The banner shares the sticky bar with the header, so a view-as preview stays in sight. -->
            <div class="sticky top-0 z-30">
                <ViewAsBanner />
                <header class="flex h-12 items-center gap-2 border-b border-border bg-background/90 px-3 backdrop-blur sm:px-4">
                    <SidebarTrigger aria-label="Toggle sidebar" />
                    <Separator orientation="vertical" class="mx-1 data-[orientation=vertical]:h-4" />

                    <Breadcrumb class="min-w-0">
                        <BreadcrumbList class="flex-nowrap gap-1.5 text-[13px] whitespace-nowrap sm:gap-1.5">
                            <BreadcrumbItem class="hidden sm:inline-flex">7CAV</BreadcrumbItem>
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

            <main id="main" tabindex="-1" class="mx-auto w-full max-w-[1440px] flex-1 px-4 py-5 outline-none sm:px-6">
                <slot />
            </main>
        </SidebarInset>
    </SidebarProvider>

    <ViewAsPicker />

    <!-- `?` on any page lists every keyboard shortcut (lib/shortcuts.js). -->
    <KeyboardShortcuts />

    <!-- The one toast region for every page, following the theme toggle: a page calls vue-sonner's
         `toast()`, never mounts its own. -->
    <Toaster :theme="theme" position="bottom-right" close-button />
</template>
