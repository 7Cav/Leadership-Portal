<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { PhBell } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { forumDate, zuluTime } from '@/lib/forumDate';
import { postJson } from '@/lib/postJson';

// The header's bell: the signed-in user's own notifications. The unread count comes with every page
// (`notificationsUnread`, null hides the bell during view as and act as); the list is read each time it opens. Opening
// one marks it read and goes to its link.
const page = usePage();
const shown = computed(() => typeof page.props.notificationsUnread === 'number');
const unread = ref(page.props.notificationsUnread ?? 0);
watch(
    () => page.props.notificationsUnread,
    (count) => (unread.value = count ?? 0),
);

const open = ref(false);
const status = ref('idle'); // idle | loading | ready | error
const items = ref([]);
let loadId = 0;

async function load() {
    const mine = ++loadId;
    status.value = 'loading';
    try {
        const response = await fetch('/notifications', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const body = await response.json();
        if (!Array.isArray(body?.notifications)) throw new Error('Unexpected response');
        if (mine !== loadId) return;
        items.value = body.notifications;
        unread.value = body.unread ?? 0;
        status.value = 'ready';
    } catch (error) {
        if (mine !== loadId) return;
        console.error('Notifications did not load', error);
        status.value = 'error';
    }
}

watch(open, (isOpen) => {
    if (isOpen) load();
});

function when(value) {
    return `${forumDate(value)} ${zuluTime(value)}`;
}

async function choose(item) {
    if (!item.read) {
        item.read = true;
        unread.value = Math.max(0, unread.value - 1);
        const result = await postJson(`/notifications/${encodeURIComponent(item.id)}/read`, {});
        if (result.ok && typeof result.data.unread === 'number') unread.value = result.data.unread;
    }
    open.value = false;
    if (item.url) router.visit(item.url);
}

async function markAll() {
    const result = await postJson('/notifications/read-all', {});
    if (result.ok) {
        items.value = items.value.map((item) => ({ ...item, read: true }));
        unread.value = 0;
    }
}
</script>

<template>
    <Popover v-if="shown" v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                variant="ghost"
                size="icon-sm"
                class="relative text-muted-foreground"
                data-testid="notification-bell"
                :aria-label="unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'"
            >
                <PhBell />
                <span
                    v-if="unread > 0"
                    data-testid="notification-count"
                    class="absolute -top-0.5 -right-0.5 min-w-4 rounded-full bg-destructive px-1 text-center text-[10px] leading-4 font-semibold text-white"
                    aria-hidden="true"
                    >{{ unread > 99 ? '99+' : unread }}</span
                >
            </Button>
        </PopoverTrigger>
        <PopoverContent align="end" class="w-80 p-0" data-testid="notification-list">
            <div class="flex items-center justify-between border-b border-border px-3 py-2">
                <p class="text-[13px] font-medium">Notifications</p>
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-7 text-xs"
                    data-testid="notification-read-all"
                    :disabled="unread === 0"
                    @click="markAll"
                >
                    Mark all read
                </Button>
            </div>
            <p v-if="status === 'loading' && items.length === 0" class="px-3 py-4 text-[13px] text-muted-foreground">Loading…</p>
            <p v-else-if="status === 'error'" class="px-3 py-4 text-[13px] text-muted-foreground">Notifications did not load. Try again.</p>
            <p v-else-if="items.length === 0" class="px-3 py-4 text-[13px] text-muted-foreground" data-testid="notification-empty">
                No notifications.
            </p>
            <ul v-else class="max-h-96 overflow-y-auto">
                <li v-for="item in items" :key="item.id" class="border-b border-border last:border-0">
                    <button
                        type="button"
                        class="flex w-full gap-2 px-3 py-2 text-left text-[13px] hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                        :data-testid="`notification-${item.id}`"
                        :data-read="item.read"
                        @click="choose(item)"
                    >
                        <span
                            class="mt-1.5 size-1.5 shrink-0 rounded-full"
                            :class="item.read ? 'bg-transparent' : 'bg-primary'"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1">
                            <span :class="item.read ? 'text-muted-foreground' : 'text-foreground'">{{ item.message }}</span>
                            <span class="mt-0.5 block text-xs text-muted-foreground">{{ when(item.createdAt) }}</span>
                            <span v-if="!item.read" class="sr-only">Unread</span>
                        </span>
                    </button>
                </li>
            </ul>
        </PopoverContent>
    </Popover>
</template>
