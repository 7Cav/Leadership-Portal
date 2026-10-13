<script setup>
import { PhWarning } from '@phosphor-icons/vue';
import InlineNotice from '@/components/InlineNotice.vue';

// The rollcall's forum post (Stories 3.10, 3.11): the BBCode the company's active template makes of this week's marks,
// re-rendered on every change to them. `post` is `rollcallPost()`'s answer. Copying and posting happen outside this
// component.
defineProps({
    post: { type: Object, required: true },
});
</script>

<template>
    <section aria-labelledby="forum-post-title" class="mt-5 rounded-lg border border-border bg-card" data-testid="forum-post">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 pt-3.5">
            <h3 id="forum-post-title" class="text-[13px] font-semibold">Forum post</h3>
            <span v-if="post.state === 'ready'" class="text-xs text-muted-foreground" data-testid="template-version">Template version {{ post.version }}</span>
            <span class="ml-auto text-xs text-muted-foreground">Updates as you edit</span>
        </div>
        <div class="p-4 pt-3">
            <pre
                v-if="post.state === 'ready'"
                class="max-h-[22rem] overflow-auto rounded-md border border-border bg-muted/60 px-4 py-3.5 font-mono text-xs leading-relaxed whitespace-pre-wrap"
                tabindex="0"
                aria-label="BBCode"
                data-testid="forum-bbcode"
            >{{ post.text }}</pre>
            <InlineNotice v-else :icon="PhWarning" tone="warning" role="status" data-testid="forum-post-unavailable">{{ post.message }}</InlineNotice>
        </div>
    </section>
</template>
