<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { stamp } from '@/lib/report';

// The training topic a platoon leader sets for their sections this week, on the platoon's own
// report screen. Enforced, it is locked on every section's report; otherwise each section starts
// with it and may change it. Only the platoon's chain of command can set it (checked again on the
// server); everyone else who can see the platoon sees what was set.
const props = defineProps({
    topic: { type: Object, default: null },
    canSet: { type: Boolean, default: false },
    readOnly: { type: Boolean, default: false },
    url: { type: String, required: true },
});

const form = reactive({ subject: props.topic?.subject ?? '', enforced: props.topic?.enforced ?? false });
const busy = ref(false);
const error = ref(null);

watch(
    () => props.topic,
    (topic) => {
        form.subject = topic?.subject ?? '';
        form.enforced = topic?.enforced ?? false;
    },
);

const editable = computed(() => props.canSet && !props.readOnly);
const changed = computed(() => form.subject.trim() !== (props.topic?.subject ?? '') || form.enforced !== (props.topic?.enforced ?? false));

const status = computed(() => {
    if (!props.topic) return 'Not set. Each section fills in its own training subject.';
    const how = props.topic.enforced ? 'Locked on every section report this week.' : 'Each section starts with it and can change it.';
    const who = props.topic.setBy ? ` Set by ${props.topic.setBy}${props.topic.setAt ? ` ${stamp(props.topic.setAt)}` : ''}.` : '';

    return `${how}${who}`;
});

function options(done) {
    return {
        preserveScroll: true,
        preserveState: true,
        only: ['topic', 'canSetTopic'],
        onSuccess: () => toast.success(done),
        onError: (errors) => (error.value = errors.topic ?? errors.subject ?? Object.values(errors)[0]),
        onHttpException: () => {
            error.value = 'The portal could not save the topic. Try again.';

            return false;
        },
        onNetworkError: () => {
            error.value = 'Could not reach the portal. Try again.';

            return false;
        },
        onFinish: () => (busy.value = false),
    };
}

function apply() {
    busy.value = true;
    error.value = null;
    router.put(props.url, { subject: form.subject.trim(), enforced: form.enforced }, options('Topic applied to your sections'));
}

function clear() {
    busy.value = true;
    error.value = null;
    router.delete(props.url, options('Topic cleared for your sections'));
}
</script>

<template>
    <div class="space-y-1.5" data-testid="platoon-topic">
        <Label for="platoon-topic" class="text-[13px]">Training topic for my sections</Label>
        <Input
            id="platoon-topic"
            v-model="form.subject"
            :disabled="!editable"
            maxlength="255"
            autocomplete="off"
            aria-describedby="platoon-topic-help"
        />
        <div v-if="editable" class="flex items-center gap-2 text-[13px]">
            <Checkbox id="platoon-topic-enforced" v-model="form.enforced" data-testid="topic-enforced" />
            <Label for="platoon-topic-enforced" class="text-[13px] font-normal">Enforce it, so sections cannot change it</Label>
        </div>
        <p id="platoon-topic-help" class="text-xs text-muted-foreground" data-testid="topic-status">{{ status }}</p>
        <div v-if="editable" class="flex flex-wrap gap-2 pt-1">
            <Button size="sm" :disabled="busy || !form.subject.trim() || !changed" data-testid="topic-apply" @click="apply">Apply to my sections</Button>
            <Button v-if="topic" size="sm" variant="ghost" :disabled="busy" data-testid="topic-clear" @click="clear">Clear</Button>
        </div>
        <p v-if="error" class="text-xs text-destructive" role="alert" data-testid="topic-error">{{ error }}</p>
    </div>
</template>
