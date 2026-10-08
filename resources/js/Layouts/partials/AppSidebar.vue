<script setup>
import { watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { PhCaretUpDown, PhCheck } from '@phosphor-icons/vue';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
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
import { isActive, visibleGroups } from '../navigation';
import UserMenu from './UserMenu.vue';

const page = usePage();
const { isAdmin } = useIdentity();
const { isMobile, setOpenMobile } = useSidebar();

// The mobile sidebar is a sheet over the page: close it once a nav link has taken the viewer
// somewhere, or it would sit on top of the page they just opened.
watch(
    () => page.url,
    () => {
        if (isMobile.value) setOpenMobile(false);
    },
);
</script>

<template>
    <Sidebar collapsible="icon" aria-label="Primary">
        <SidebarHeader class="border-b border-sidebar-border p-2">
            <!--
              Scope switcher (shell-f.html `#unitSwitcher`). Only the regiment exists as a scope
              until unit scopes and view-as land (Story 1.13), so the menu says so rather than
              offering choices that do nothing.
            -->
            <SidebarMenu>
                <SidebarMenuItem>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <SidebarMenuButton size="lg" data-testid="scope-switcher" aria-label="Scope: 7th Cavalry Regiment">
                                <span class="grid size-8 shrink-0 place-items-center rounded-md bg-foreground">
                                    <img src="/assets/cav-shield.png" alt="" class="size-6 object-contain" />
                                </span>
                                <span class="grid min-w-0 flex-1 leading-tight">
                                    <span class="truncate text-[13px] font-semibold">7th Cavalry</span>
                                    <span class="truncate text-xs text-muted-foreground">Leadership Portal</span>
                                </span>
                                <PhCaretUpDown class="ml-auto size-4 text-muted-foreground" />
                            </SidebarMenuButton>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent class="w-64" align="start" :side-offset="6">
                            <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Scope</DropdownMenuLabel>
                            <DropdownMenuItem class="gap-2">
                                <span class="font-medium">7CAV</span>
                                <span class="text-muted-foreground">7th Cavalry Regiment</span>
                                <PhCheck class="ml-auto size-4" aria-label="Current scope" />
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <p class="px-2 py-1.5 text-xs text-muted-foreground">
                                Switching to a battalion, company or platoon is coming soon.
                            </p>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-2 py-2">
            <SidebarGroup v-for="group in visibleGroups(isAdmin)" :key="group.id" :data-testid="`nav-group-${group.id}`" class="py-0">
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
                            >
                                <component :is="item.icon" />
                                <span>{{ item.label }}</span>
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
        </SidebarContent>

        <SidebarFooter class="border-t border-sidebar-border p-2">
            <UserMenu />
        </SidebarFooter>
    </Sidebar>
</template>
