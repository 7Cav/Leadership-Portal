<script setup>
import { Link } from '@inertiajs/vue3';
import { PhArrowCounterClockwise, PhArrowsClockwise, PhDotsThreeVertical, PhMoon, PhSignOut, PhSun, PhUserFocus, PhUserSwitch } from '@phosphor-icons/vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useActAs } from '@/composables/useActAs';
import { useIdentity } from '@/composables/useIdentity';
import { useTheme } from '@/composables/useTheme';
import { useViewAs } from '@/composables/useViewAs';

// The identity cluster (Story 1.5–1.7, moved here from Home.vue in Story 1.15): who is signed in,
// where their rank and billet came from (AD-20), the theme, and Sign out.
const { name, billet, identity, provenance, provenanceDetail, initials } = useIdentity();
const { theme, setTheme } = useTheme();
const { active: viewingAs, openPicker, exit: exitViewAs } = useViewAs();
const { canActAs, openPicker: openActAsPicker } = useActAs();
</script>

<template>
    <SidebarMenu>
        <SidebarMenuItem>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <SidebarMenuButton size="lg" class="h-auto min-h-12 py-1.5" data-testid="user-menu" :aria-label="`Account menu for ${identity}`">
                        <Avatar class="border border-border bg-muted">
                            <AvatarFallback class="bg-muted text-xs font-semibold" aria-hidden="true">{{ initials }}</AvatarFallback>
                        </Avatar>
                        <span class="grid min-w-0 flex-1 leading-tight">
                            <span class="truncate text-[13px] font-medium">{{ name }}</span>
                            <span v-if="billet" class="truncate text-xs text-muted-foreground">{{ billet }}</span>
                            <!-- Rank and billet are synced values, so their source rides along here too (AD-20). -->
                            <span v-if="provenance" class="truncate text-[11px] text-muted-foreground" :title="provenanceDetail ?? undefined">{{
                                provenance
                            }}</span>
                        </span>
                        <PhDotsThreeVertical class="ml-auto size-4 text-muted-foreground" />
                    </SidebarMenuButton>
                </DropdownMenuTrigger>

                <DropdownMenuContent class="w-(--reka-dropdown-menu-trigger-width) min-w-60" side="top" align="start" :side-offset="6">
                    <DropdownMenuLabel class="font-normal">
                        <span class="identity block text-[13px] font-medium">{{ identity }}</span>
                        <!--
                          Provenance (AD-20 rule 1 / NFR7): rendered whenever there is a synced value
                          to attribute, and visible, not tucked in a tooltip; the title only adds the
                          full rank title and exact time.
                        -->
                        <span
                            v-if="provenance"
                            class="provenance mt-1 flex items-start gap-1 text-xs text-muted-foreground"
                            :title="provenanceDetail ?? undefined"
                        >
                            <PhArrowsClockwise class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                            <span>{{ provenance }}</span>
                        </span>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />

                    <!-- View as (Story 1.13): the same picker the top-bar control opens. -->
                    <DropdownMenuItem data-testid="view-as" @select="openPicker">
                        <PhUserSwitch class="size-4 text-muted-foreground" />
                        View as...
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="viewingAs" data-testid="view-as-menu-exit" @select="exitViewAs()">
                        <PhArrowCounterClockwise class="size-4 text-muted-foreground" />
                        Exit view as
                    </DropdownMenuItem>
                    <!-- Act as (#432): a leader steps into a billet holder below them, with their permissions. -->
                    <DropdownMenuItem v-if="canActAs" data-testid="act-as" @select="openActAsPicker">
                        <PhUserFocus class="size-4 text-muted-foreground" />
                        Act as...
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />

                    <DropdownMenuLabel class="py-1 text-xs font-normal text-muted-foreground">Theme</DropdownMenuLabel>
                    <DropdownMenuRadioGroup :model-value="theme" @update:model-value="setTheme">
                        <DropdownMenuRadioItem value="light" data-testid="theme-light">
                            <PhSun class="size-4 text-muted-foreground" />
                            Light
                        </DropdownMenuRadioItem>
                        <DropdownMenuRadioItem value="dark" data-testid="theme-dark">
                            <PhMoon class="size-4 text-muted-foreground" />
                            Dark
                        </DropdownMenuRadioItem>
                    </DropdownMenuRadioGroup>
                    <DropdownMenuSeparator />

                    <!--
                      A POST, not a link: ending a session is a state change, so it goes through
                      CSRF protection (Story 1.5). Inertia attaches the XSRF header itself.
                    -->
                    <DropdownMenuItem as-child>
                        <Link href="/logout" method="post" as="button" type="button" class="w-full" data-testid="sign-out">
                            <PhSignOut class="size-4 text-muted-foreground" />
                            Sign out
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </SidebarMenuItem>
    </SidebarMenu>
</template>
