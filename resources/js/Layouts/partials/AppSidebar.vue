<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { PhCaretUpDown, PhCheck } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useIdentity } from '@/composables/useIdentity';
import { currentUnitId, scopeLabel, unitHref } from '@/lib/scopeSwitch';
import { isActive, visibleGroups } from '../navigation';
import UserMenu from './UserMenu.vue';

const page = usePage();
const { isAdmin } = useIdentity();
const { isMobile, setOpenMobile } = useSidebar();

// The top of the scope, shared on every page; the full list is read only when the menu opens, since an Admin's is the
// whole regiment. Read again on each opening: view as or act as may have changed the scope since.
const tops = computed(() => page.props.scopeTop ?? []);
const label = computed(() => scopeLabel(tops.value));
const scopeName = computed(() => (tops.value.length === 1 ? tops.value[0].name : tops.value.length ? `${tops.value.length} units` : 'Leadership Portal'));
const currentId = computed(() => currentUnitId(page.props));

const menuOpen = ref(false);
const status = ref('idle'); // idle | loading | ready | error
const units = ref([]);

async function load() {
    status.value = 'loading';
    try {
        const response = await fetch('/scope/units', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        units.value = (await response.json()).units ?? [];
        status.value = 'ready';
    } catch {
        status.value = 'error';
    }
}

watch(menuOpen, (isOpen) => {
    if (isOpen) load();
});

function open(unit) {
    closeOnMobile();
    router.visit(unitHref(unit, page.url));
}

// The mobile sidebar is a sheet over the page: close it when a nav link is followed (including
// the page already open), or it would sit on top of the page the viewer asked for.
function closeOnMobile() {
    if (isMobile.value) setOpenMobile(false);
}
</script>

<template>
    <Sidebar collapsible="icon">
        <SidebarHeader class="border-b border-sidebar-border p-2">
            <!--
              The unit switcher (shell-f.html `#unitSwitcher`, #426): the top of the viewer's scope as this request
              sees it (view as and act as included), and a menu of every unit in that scope to open a page for.
              Navigation only: it never changes scope, and each page checks the unit again.
            -->
            <SidebarMenu>
                <SidebarMenuItem>
                    <DropdownMenu v-model:open="menuOpen">
                        <DropdownMenuTrigger as-child>
                            <SidebarMenuButton size="lg" data-testid="scope-switcher" :aria-label="`Scope: ${scopeName}`">
                                <span class="grid size-8 shrink-0 place-items-center rounded-md bg-foreground">
                                    <img src="/assets/cav-shield.png" alt="" class="size-6 object-contain" />
                                </span>
                                <span class="grid min-w-0 flex-1 leading-tight">
                                    <span class="truncate text-[13px] font-semibold" data-testid="scope-switcher-label">{{ label }}</span>
                                    <span class="truncate text-xs text-muted-foreground">{{ scopeName }}</span>
                                </span>
                                <PhCaretUpDown class="ml-auto size-4 text-muted-foreground" />
                            </SidebarMenuButton>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent class="w-72" align="start" :side-offset="6" data-testid="scope-switcher-menu">
                            <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Open a unit</DropdownMenuLabel>
                            <p v-if="status === 'loading'" class="px-2 py-1.5 text-xs text-muted-foreground" role="status">Loading your units</p>
                            <p v-else-if="status === 'error'" class="px-2 py-1.5 text-xs text-muted-foreground" role="alert">
                                Your units could not be loaded. Close the menu and open it again.
                            </p>
                            <p v-else-if="status === 'ready' && units.length === 0" class="px-2 py-1.5 text-xs text-muted-foreground">
                                No units in your scope.
                            </p>
                            <div v-else-if="status === 'ready'" class="max-h-[60vh] overflow-y-auto">
                                <DropdownMenuItem
                                    v-for="unit in units"
                                    :key="unit.id"
                                    class="gap-2"
                                    :style="{ paddingLeft: `${0.5 + Math.min(unit.depth, 6) * 0.75}rem` }"
                                    :data-unit="unit.id"
                                    @select="open(unit)"
                                >
                                    <span class="font-medium">{{ unit.designation }}</span>
                                    <span v-if="unit.name !== unit.designation" class="truncate text-muted-foreground">{{ unit.name }}</span>
                                    <PhCheck v-if="unit.id === currentId" class="ml-auto size-4" aria-label="Unit on screen" />
                                </DropdownMenuItem>
                            </div>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="py-2">
            <!-- The primary-navigation landmark (the sidebar itself renders plain divs). -->
            <nav aria-label="Primary" class="flex flex-col gap-2">
                <SidebarGroup v-for="group in visibleGroups(isAdmin, page.props.features, page.props)" :key="group.id" :data-testid="`nav-group-${group.id}`" class="py-0">
                    <SidebarGroupLabel class="h-7 text-xs font-normal text-muted-foreground">{{ group.label }}</SidebarGroupLabel>
                    <SidebarMenu class="gap-0.5">
                        <SidebarMenuItem v-for="item in group.items" :key="item.label">
                            <SidebarMenuButton
                                v-if="item.href"
                                as-child
                                :is-active="isActive(page.url, item.href)"
                                :tooltip="item.label"
                            >
                                <Link
                                    :href="item.href"
                                    :aria-current="isActive(page.url, item.href) ? 'page' : undefined"
                                    :data-testid="`nav-${item.label}`"
                                    @click="closeOnMobile"
                                >
                                    <component :is="item.icon" />
                                    <span>{{ item.label }}</span>
                                    <!-- Something on that screen waits for an Admin (the Admin billets to confirm). -->
                                    <Badge
                                        v-if="item.count && page.props[item.count] > 0"
                                        variant="warning"
                                        class="ml-auto group-data-[collapsible=icon]:hidden"
                                        :data-testid="`nav-count-${item.label}`"
                                    >
                                        {{ page.props[item.count] }}<span class="sr-only"> waiting for an Admin</span>
                                    </Badge>
                                </Link>
                            </SidebarMenuButton>

                            <!--
                              No screen yet: a disabled control, not a dead link. It stays focusable
                              (aria-disabled, not `disabled`) so keyboard users reach the tooltip, and
                              carries a visible "Soon" for touch, where tooltips never open.
                            -->
                            <Tooltip v-else>
                                <TooltipTrigger as-child>
                                    <SidebarMenuButton
                                        type="button"
                                        aria-disabled="true"
                                        :data-testid="`nav-${item.label}`"
                                        class="cursor-not-allowed aria-disabled:pointer-events-auto hover:bg-transparent"
                                        @click.prevent
                                    >
                                        <component :is="item.icon" />
                                        <span class="flex-1">{{ item.label }}</span>
                                        <Badge variant="outline" class="group-data-[collapsible=icon]:hidden">Soon</Badge>
                                    </SidebarMenuButton>
                                </TooltipTrigger>
                                <TooltipContent side="right">{{ item.label }}: coming soon</TooltipContent>
                            </Tooltip>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarGroup>
            </nav>
        </SidebarContent>

        <SidebarFooter class="border-t border-sidebar-border p-2">
            <UserMenu />
        </SidebarFooter>
    </Sidebar>
</template>
