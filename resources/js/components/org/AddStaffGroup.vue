<script setup>
import { ref } from 'vue';
import { PhPlus } from '@phosphor-icons/vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { onHttpException } from '@/lib/httpError';

// Add a staff group (a portfolio or a department) under the regiment or under a staff group, for an Admin
// (2026-11-13). The same add the line units take (`store`, CreateUnit, audited as `unit.created`); a group is named by
// hand and takes no callsign. Add billets to it from its Billets list.
const props = defineProps({
    parent: { type: Object, required: true },
});

const open = ref(false);
const name = ref('');
const error = ref(null);
const sending = ref(false);

function show() {
    name.value = '';
    error.value = null;
    open.value = true;
}

function submit() {
    if (sending.value || !name.value.trim()) return;
    error.value = null;
    sending.value = true;
    router.post(
        '/settings/org-structure',
        { type: 'group', parent_id: props.parent.id, name: name.value.trim() },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                toast.success(`Added ${name.value.trim()}.`);
            },
            onError: (errors) => {
                error.value = errors.name ?? errors.type ?? errors.parent_id ?? Object.values(errors)[0] ?? 'The group was not added. Try again.';
            },
            onHttpException: onHttpException('The group was not added', (message) => {
                error.value = message;
            }),
            onFinish: () => {
                sending.value = false;
            },
        },
    );
}
</script>

<template>
    <Button type="button" variant="outline" class="mt-2 w-full" data-testid="add-staff-group-open" @click="show">
        <PhPlus aria-hidden="true" />Add a staff group under {{ parent.name }}
    </Button>
    <Dialog :open="open" @update:open="(value) => !value && !sending && (open = false)">
        <DialogContent class="sm:max-w-md" data-testid="add-staff-group-dialog">
            <DialogHeader>
                <DialogTitle>Add a staff group</DialogTitle>
                <DialogDescription>Under {{ parent.name }}. Add the billets it holds from its Billets list.</DialogDescription>
            </DialogHeader>
            <form class="space-y-3" data-testid="add-staff-group-form" @submit.prevent="submit">
                <div class="grid gap-1">
                    <Label for="add-staff-group-name">Name</Label>
                    <Input id="add-staff-group-name" v-model="name" data-testid="add-staff-group-name" maxlength="255" placeholder="Such as Member Advocate" />
                </div>
                <p v-if="error" role="alert" class="text-xs text-destructive" data-testid="add-staff-group-error">{{ error }}</p>
                <DialogFooter>
                    <Button type="button" variant="ghost" :disabled="sending" @click="open = false">Cancel</Button>
                    <Button type="submit" data-testid="add-staff-group-submit" :disabled="sending || !name.trim()">{{ sending ? 'Adding' : 'Add group' }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
