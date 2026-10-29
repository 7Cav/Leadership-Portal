<script setup>
import { ref } from 'vue';
import { PhCode, PhEye, PhWarning } from '@phosphor-icons/vue';
import BbcodePreview from '@/components/BbcodePreview.vue';
import InlineNotice from '@/components/InlineNotice.vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

// The rollcall's forum post (Stories 3.10, 3.11): the BBCode the company's active template makes of this week's marks,
// re-rendered on every change to them. `post` is `rollcallPost()`'s answer. It opens on a preview drawn as the forum
// shows the post; the BBCode tab holds the text that is copied. Both stay in the page while hidden, so the BBCode is
// always there to select by hand. Copying and posting happen outside this component.
defineProps({
    post: { type: Object, required: true },
});

const view = ref('preview');
</script>

<template>
    <section aria-labelledby="forum-post-title" class="mt-5 rounded-lg border border-border bg-card" data-testid="forum-post">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 pt-3.5">
            <h3 id="forum-post-title" class="text-[13px] font-semibold">Roll call post</h3>
            <span v-if="post.state === 'ready'" class="text-xs text-muted-foreground" data-testid="template-version">Template version {{ post.version }}</span>
            <span class="ml-auto text-xs text-muted-foreground">Updates as you edit</span>
        </div>
        <Tabs v-if="post.state === 'ready'" v-model="view" :unmount-on-hide="false" class="gap-0 px-4 pt-1 pb-4">
            <TabsList aria-label="Roll call post view">
                <TabsTrigger value="preview" data-testid="forum-view-preview"><PhEye aria-hidden="true" />Preview</TabsTrigger>
                <TabsTrigger value="bbcode" data-testid="forum-view-bbcode"><PhCode aria-hidden="true" />BBCode</TabsTrigger>
            </TabsList>
            <TabsContent value="preview" class="pt-3">
                <div class="max-h-[32rem] overflow-auto rounded-md border border-border bg-background px-5 py-4" data-testid="forum-preview">
                    <BbcodePreview :source="post.text" />
                </div>
            </TabsContent>
            <TabsContent value="bbcode" class="pt-3">
                <pre
                    class="max-h-[22rem] overflow-auto rounded-md border border-border bg-muted/60 px-4 py-3.5 font-mono text-xs leading-relaxed whitespace-pre-wrap"
                    tabindex="0"
                    aria-label="BBCode"
                    data-testid="forum-bbcode"
                >{{ post.text }}</pre>
            </TabsContent>
        </Tabs>
        <div v-else class="p-4 pt-3">
            <InlineNotice :icon="PhWarning" tone="warning" role="status" data-testid="forum-post-unavailable">{{ post.message }}</InlineNotice>
        </div>
    </section>
</template>
