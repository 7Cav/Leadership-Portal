<script setup>
import { computed } from 'vue';
import { PhBookmarkSimple } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useBookmarks } from '@/composables/useBookmarks';

// The top bar's bookmark button, beside the breadcrumb: only on a page that can be bookmarked, filled when it already
// is. Pressed again, it removes the bookmark. With every slot in use it adds nothing and says why.
const { current, full, limit, add, remove } = useBookmarks();

const bookmarked = computed(() => current.value?.bookmarkId != null);
const blocked = computed(() => !bookmarked.value && full.value);
const hint = computed(() => {
    if (bookmarked.value) return 'Remove bookmark';
    if (blocked.value) return `All ${limit.value} bookmark slots are in use. Remove one to add this page.`;

    return 'Bookmark this page';
});

function toggle() {
    if (blocked.value) return;
    if (bookmarked.value) remove(current.value.bookmarkId);
    else add(current.value);
}
</script>

<template>
    <Tooltip v-if="current">
        <TooltipTrigger as-child>
            <Button
                variant="ghost"
                size="icon-sm"
                class="shrink-0 text-muted-foreground"
                data-testid="bookmark-toggle"
                :aria-label="hint"
                :aria-pressed="bookmarked"
                :aria-disabled="blocked || undefined"
                :data-bookmarked="bookmarked"
                @click="toggle"
            >
                <PhBookmarkSimple :weight="bookmarked ? 'fill' : 'regular'" />
            </Button>
        </TooltipTrigger>
        <TooltipContent side="bottom" class="max-w-64">{{ hint }}</TooltipContent>
    </Tooltip>
</template>
