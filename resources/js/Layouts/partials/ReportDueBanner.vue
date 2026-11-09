<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { PhWarning } from '@phosphor-icons/vue';
import { zuluTime } from '@/lib/forumDate';

// The reports the signed-in user still owes on the Saturday they fall due (`reportsDue`, null outside that window and
// while viewing or acting as someone). One line per report, each linking to it.
const page = usePage();
const due = computed(() => page.props.reportsDue ?? null);
const time = computed(() => (due.value ? zuluTime(due.value.due) : ''));
</script>

<template>
    <div
        v-if="due && due.reports.length"
        role="status"
        data-testid="report-due-banner"
        class="flex items-start gap-2.5 border-b border-warning/35 bg-warning/8 px-3 py-2 text-[13px] sm:px-4"
    >
        <PhWarning class="mt-0.5 size-4 shrink-0 text-warning" weight="bold" aria-hidden="true" />
        <ul class="min-w-0 flex-1 space-y-0.5">
            <li v-for="report in due.reports" :key="report.href" data-testid="report-due-item">
                <Link :href="report.href" class="underline underline-offset-2 hover:decoration-2">
                    <template v-if="report.kind === 'staff'">Your staff report</template>
                    <template v-else>Your {{ report.label }} weekly report</template>
                </Link>
                is due at {{ time }} today.
            </li>
        </ul>
    </div>
</template>
