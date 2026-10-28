<script setup>
import { ref } from 'vue';
import { PhThumbsDown, PhThumbsUp } from '@phosphor-icons/vue';
import { Button } from '@/components/ui/button';

// "Did this answer your question?" (#410). Nothing is stored: No sends the reader to the forum thread or mailto link
// S6 set (DOCS_FEEDBACK_URL), so what was missing reaches a person.
const props = defineProps({
    // Where No sends the reader, or null when none is set.
    url: { type: String, default: null },
});

const answer = ref(null);

function no() {
    answer.value = 'no';
    if (props.url) window.open(props.url, '_blank', 'noopener');
}
</script>

<template>
    <div class="mt-7 flex flex-wrap items-center gap-2.5 text-[13px] text-muted-foreground" data-testid="guide-feedback">
        <template v-if="answer === null">
            <span>Did this answer your question?</span>
            <Button variant="outline" @click="answer = 'yes'"><PhThumbsUp />Yes</Button>
            <Button variant="outline" @click="no"><PhThumbsDown />No</Button>
        </template>
        <p v-else-if="answer === 'yes'" role="status">Thanks for letting us know.</p>
        <p v-else role="status">
            Thanks.
            <template v-if="url">
                Tell us what was missing in the
                <a :href="url" target="_blank" rel="noopener" class="underline underline-offset-2">page that just opened</a>.
            </template>
            <template v-else>Ask your chain of command, or S6, about what was missing.</template>
        </p>
    </div>
</template>
