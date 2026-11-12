<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { PhArrowDown, PhArrowUp, PhBookmarkSimple, PhDotsThree, PhTrash } from '@phosphor-icons/vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useBookmarks } from '@/composables/useBookmarks';

// The sidebar's Bookmarks section, between Leadership and Help: the person's bookmarks in their order. Not shown with
// none, nor during view as, act as or the tutorial. Each has a small menu to move it or remove it.
defineEmits(['navigate']);

const page = usePage();
const { items, remove, move } = useBookmarks();

function pathOf(url) {
    return (url ?? '').split(/[?#]/)[0].replace(/\/+$/, '') || '/';
}
</script>

<template>
    <SidebarGroup v-if="items.length" data-testid="nav-group-bookmarks" class="py-0">
        <SidebarGroupLabel class="h-7 text-xs font-normal text-muted-foreground">Bookmarks</SidebarGroupLabel>
        <SidebarMenu class="gap-0.5">
            <SidebarMenuItem v-for="(item, index) in items" :key="item.id" :data-bookmark="item.id">
                <SidebarMenuButton as-child :is-active="pathOf(page.url) === item.href" :tooltip="item.label">
                    <Link
                        :href="item.href"
                        :aria-current="pathOf(page.url) === item.href ? 'page' : undefined"
                        data-testid="bookmark-link"
                        @click="$emit('navigate')"
                    >
                        <PhBookmarkSimple />
                        <span class="truncate">{{ item.label }}</span>
                    </Link>
                </SidebarMenuButton>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <SidebarMenuAction show-on-hover data-testid="bookmark-menu" :aria-label="`Options for ${item.label}`">
                            <PhDotsThree weight="bold" />
                        </SidebarMenuAction>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side="right" align="start" data-testid="bookmark-menu-content">
                        <DropdownMenuItem v-if="index > 0" data-testid="bookmark-up" @select="move(item.id, 'up')">
                            <PhArrowUp /> Move up
                        </DropdownMenuItem>
                        <DropdownMenuItem v-if="index < items.length - 1" data-testid="bookmark-down" @select="move(item.id, 'down')">
                            <PhArrowDown /> Move down
                        </DropdownMenuItem>
                        <DropdownMenuItem data-testid="bookmark-remove" @select="remove(item.id)">
                            <PhTrash /> Remove
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
