<script setup>
import { computed } from 'vue';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { rollcallBbcode, rollcallPost } from '@/lib/rollcallBbcode';

// The rollcall's forum post (Story 3.10): a live preview and the BBCode, both from the same data,
// re-rendered on every change to the marks. Copying and posting happen outside this component.
const props = defineProps({
    designation: { type: String, required: true },
    weekStart: { type: String, required: true },
    troopers: { type: Array, required: true },
});

const input = computed(() => ({ designation: props.designation, weekStart: props.weekStart, troopers: props.troopers }));
const post = computed(() => rollcallPost(input.value));
const bbcode = computed(() => rollcallBbcode(input.value));

defineExpose({ bbcode });

const tone = (value) => ({ Missed: 'text-destructive', 'Not marked': 'text-warning', Unsigned: 'text-warning' })[value] ?? '';
</script>

<template>
    <section aria-labelledby="forum-post-title" class="mt-5 rounded-lg border border-border bg-card" data-testid="forum-post">
        <Tabs default-value="preview">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 pt-3.5">
                <h3 id="forum-post-title" class="text-[13px] font-semibold">Forum post</h3>
                <TabsList aria-label="Forum post view">
                    <TabsTrigger value="preview">Preview</TabsTrigger>
                    <TabsTrigger value="code">BBCode</TabsTrigger>
                </TabsList>
                <span class="ml-auto text-xs text-muted-foreground">Updates as you edit</span>
            </div>
            <TabsContent value="preview" class="p-4 pt-3">
                <p class="mb-2 text-[13px] font-semibold">{{ post.title }}</p>
                <div v-if="post.rows.length" class="overflow-x-auto rounded-md">
                    <table class="w-full border-collapse text-[13px]" data-testid="forum-preview">
                        <thead>
                            <tr>
                                <th v-for="column in post.columns" :key="column" class="border border-border bg-muted px-2.5 py-1.5 text-left font-semibold whitespace-nowrap">
                                    {{ column }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in post.rows" :key="row.id">
                                <td class="border border-border px-2.5 py-1.5 whitespace-nowrap">{{ row.rank }}</td>
                                <td class="border border-border px-2.5 py-1.5 whitespace-nowrap">{{ row.name }}</td>
                                <td :class="['border border-border px-2.5 py-1.5 whitespace-nowrap', tone(row.signature)]">{{ row.signature }}</td>
                                <td :class="['border border-border px-2.5 py-1.5 whitespace-nowrap', tone(row.practice)]">{{ row.practice }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs text-muted-foreground">{{ post.summary }}</p>
            </TabsContent>
            <TabsContent value="code" class="p-4 pt-3">
                <pre
                    class="max-h-[22rem] overflow-auto rounded-md border border-border bg-muted/60 px-4 py-3.5 font-mono text-xs leading-relaxed whitespace-pre"
                    tabindex="0"
                    aria-label="BBCode"
                    data-testid="forum-bbcode"
                >{{ bbcode }}</pre>
            </TabsContent>
        </Tabs>
    </section>
</template>
