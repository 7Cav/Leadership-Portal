<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { PhPlus } from '@phosphor-icons/vue';
import MilpacPanel from '@/components/milpac/MilpacPanel.vue';
import StaffPracticeList from '@/components/roster/StaffPracticeList.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { onHttpException } from '@/lib/httpError';
import { weekLabel } from '@/lib/roster';
import { toast } from 'vue-sonner';

// The section practices a staff member attended (#448). Staff are on no section's rollcall, so the staff member, their
// staff leader or an Admin enters which section's practice and which Cav week. The server sends the entries, whether
// this viewer may add one, and the sections and weeks the form offers; hiding a control is cosmetic, every route checks
// again.
const props = defineProps({
    trooperId: { type: Number, required: true },
    // From TrooperDetailController: `{ entries, canRecord, sections, weeks }`.
    staffPractices: { type: Object, required: true },
});

const adding = ref(false);
const removing = ref(false);
const form = useForm({ section_id: '', week: '' });

function openForm() {
    form.reset();
    form.clearErrors();
    form.week = props.staffPractices.weeks?.[0] ?? '';
    adding.value = true;
}

function submit() {
    form.post(`/troopers/${props.trooperId}/staff-practices`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Practice recorded');
            adding.value = false;
            form.reset();
        },
        onHttpException: onHttpException('The practice was not recorded', (message) => toast.error(message)),
    });
}

function remove(entry) {
    removing.value = true;
    router.post(
        `/troopers/${props.trooperId}/staff-practices/remove`,
        { section_id: entry.sectionId, week: entry.week },
        {
            preserveScroll: true,
            onSuccess: () => toast.success('Practice removed'),
            onError: (errors) => toast.error(Object.values(errors)[0] ?? 'The practice was not removed. Try again.'),
            onHttpException: onHttpException('The practice was not removed', (message) => toast.error(message)),
            onFinish: () => {
                removing.value = false;
            },
        },
    );
}
</script>

<template>
    <MilpacPanel title="Section practices attended" data-testid="staff-practices-panel">
        <template v-if="staffPractices.entries.length" #count>
            <Badge
                variant="secondary"
                class="rounded-full tabular-nums"
                :aria-label="`${staffPractices.entries.length} ${staffPractices.entries.length === 1 ? 'practice' : 'practices'}`"
                data-testid="staff-practices-count"
            >
                {{ staffPractices.entries.length }}
            </Badge>
        </template>

        <div class="flex flex-col gap-3 border-b border-border px-4 py-3 text-[13px]">
            <p class="text-muted-foreground">
                Staff are on no section's roll call, so the practices they attend are kept here. They never count for the host section.
                A game server entry means the capture saw them on the server that Cav week.
            </p>

            <div v-if="staffPractices.canRecord && !adding">
                <Button variant="outline" size="sm" data-testid="staff-practice-open" @click="openForm"><PhPlus aria-hidden="true" />Add a practice</Button>
            </div>

            <form v-if="adding" class="space-y-3" data-testid="staff-practice-form" @submit.prevent="submit">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-1.5">
                        <Label for="staff-practice-section">Section</Label>
                        <NativeSelect id="staff-practice-section" v-model="form.section_id" data-testid="staff-practice-section" :aria-invalid="!!form.errors.section_id">
                            <option value="" disabled>Pick a section</option>
                            <option v-for="section in staffPractices.sections" :key="section.id" :value="section.id">{{ section.label }}</option>
                        </NativeSelect>
                        <p v-if="form.errors.section_id" class="text-xs text-destructive" data-testid="staff-practice-error-section">{{ form.errors.section_id }}</p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="staff-practice-week">Cav week</Label>
                        <NativeSelect id="staff-practice-week" v-model="form.week" data-testid="staff-practice-week" :aria-invalid="!!form.errors.week">
                            <option v-for="(week, index) in staffPractices.weeks" :key="week" :value="week">
                                Week of {{ weekLabel(week) }}{{ index === 0 ? ' (this week)' : '' }}
                            </option>
                        </NativeSelect>
                        <p v-if="form.errors.week" class="text-xs text-destructive" data-testid="staff-practice-error-week">{{ form.errors.week }}</p>
                    </div>
                </div>
                <p v-if="form.errors.trooper" class="text-xs text-destructive" data-testid="staff-practice-error-trooper">{{ form.errors.trooper }}</p>

                <div class="flex gap-2">
                    <Button type="submit" size="sm" :disabled="form.processing || !form.section_id" data-testid="staff-practice-submit">Record practice</Button>
                    <Button type="button" size="sm" variant="outline" data-testid="staff-practice-cancel" @click="adding = false">Cancel</Button>
                </div>
            </form>
        </div>

        <p v-if="!staffPractices.entries.length" class="px-4 py-6 text-center text-[13px] text-muted-foreground" data-testid="staff-practices-empty">
            No section practices on record.
        </p>
        <div v-else class="max-h-96 overflow-y-auto px-4 py-3" tabindex="0" aria-label="Section practices attended, newest first">
            <StaffPracticeList :entries="staffPractices.entries" :busy="removing" @remove="remove" />
        </div>
    </MilpacPanel>
</template>
