<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { PhEye } from '@phosphor-icons/vue';
import { toast } from 'vue-sonner';
import { AlertDialog, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { useViewAs } from '@/composables/useViewAs';
import { onHttpException } from '@/lib/httpError';

// The view-as banner (Story 1.13, binding design: mockups/v2/shell-f.html `#viewAsBanner`). It
// rides in the sticky top bar, above the header, so it stays on screen however far the page
// scrolls: a leader must never forget they are looking through someone else's eyes.
const { viewAs, subject, exit } = useViewAs();
const leaving = ref(false);

function leave() {
    leaving.value = true;
    exit({
        onHttpException: onHttpException('View as did not end', (message) => toast.error(message)),
        onFinish: () => (leaving.value = false),
    });
}

// Act as (#379): from a preview, an Admin can take over with its permissions, writes included. A person ("Act as them";
// another Admin is acted as without their Admin rights), or a unit's billet with no person behind it ("Act as this
// billet"). Only an Admin is offered either (`viewAs.actAs`: the real user is an Admin, whoever the preview shows); the
// server checks anyway.
// Someone on the roster who has never signed in has no account to act for, so acting as them is acting as the billet
// they hold; with none, there is nothing to act as.
const throughBillet = computed(() => viewAs.value?.kind === 'trooper');
const actsOnBillet = computed(() => viewAs.value?.kind === 'unit' || throughBillet.value);
const canActAs = computed(
    () => viewAs.value?.actAs === true && (viewAs.value?.kind === 'user' || viewAs.value?.kind === 'unit' || (throughBillet.value && Boolean(viewAs.value.designation))),
);
const actLabel = computed(() => (throughBillet.value ? 'Act as their billet' : actsOnBillet.value ? 'Act as this billet' : 'Act as them'));
const confirming = ref(false);
const starting = ref(false);
const actError = ref(null);

function actAs() {
    starting.value = true;
    actError.value = null;
    router.post(
        '/act-as',
        { kind: viewAs.value.kind, id: viewAs.value.id },
        {
            onSuccess: () => (confirming.value = false),
            onError: (errors) => (actError.value = errors.act_as ?? 'You cannot act as this person.'),
            onHttpException: onHttpException('Acting did not start', (message) => (actError.value = message)),
            onFinish: () => (starting.value = false),
        },
    );
}
</script>

<template>
    <div
        v-if="viewAs"
        role="status"
        data-testid="view-as-banner"
        class="flex h-9 items-center gap-2.5 bg-primary px-3 text-[13px] text-primary-foreground sm:px-4"
    >
        <PhEye class="size-4 shrink-0" weight="bold" aria-hidden="true" />
        <p class="min-w-0 flex-1 truncate">
            Viewing as <strong class="font-semibold">{{ subject }}</strong>
            <template v-if="viewAs.kind !== 'unit' && viewAs.designation">
                <span class="font-mono text-[12.5px]"> · {{ viewAs.designation }}</span>
            </template>
            <template v-else-if="viewAs.kind === 'unit' && viewAs.unitName !== viewAs.designation">
                <span class="hidden sm:inline"> ({{ viewAs.unitName }})</span>
            </template>
            <span class="md:hidden" data-testid="view-as-read-only-short">. Read only.</span>
            <span class="hidden md:inline" data-testid="view-as-read-only">. Read only: nothing can be changed while viewing as.</span>
        </p>
        <button
            v-if="canActAs"
            type="button"
            data-testid="act-as-open"
            class="shrink-0 rounded-sm font-medium whitespace-nowrap underline underline-offset-2 outline-none hover:decoration-2 focus-visible:ring-2 focus-visible:ring-primary-foreground/70"
            @click="confirming = true"
        >
            {{ actLabel }}
        </button>
        <button
            type="button"
            data-testid="view-as-exit"
            class="shrink-0 rounded-sm font-medium whitespace-nowrap underline underline-offset-2 outline-none hover:decoration-2 focus-visible:ring-2 focus-visible:ring-primary-foreground/70 disabled:opacity-60"
            :disabled="leaving"
            @click="leave"
        >
            Exit view as
        </button>
    </div>

    <AlertDialog :open="confirming" @update:open="(open) => (confirming = open)">
        <AlertDialogContent class="sm:max-w-md" data-testid="act-as-dialog">
            <AlertDialogTitle class="text-base leading-snug font-semibold">
                {{ actsOnBillet ? `Act as the leader of ${viewAs?.designation}?` : `Act as ${viewAs?.label}?` }}
            </AlertDialogTitle>
            <AlertDialogDescription v-if="actsOnBillet" class="text-[13px] text-muted-foreground">
                <template v-if="throughBillet">{{ viewAs?.label }} has never signed in, so you act as the billet they hold.</template>
                You get exactly the permissions of that unit's leader, whoever holds the billet or if nobody does, and lose
                your Admin ones until you stop. Everything you change is real and is recorded in your name, as that billet.
            </AlertDialogDescription>
            <AlertDialogDescription v-else class="text-[13px] text-muted-foreground">
                You get exactly their permissions, never Admin ones (not even theirs), and lose your own Admin ones until
                you stop. Everything you change is real and the audit log records it as you acting for them.
            </AlertDialogDescription>
            <p v-if="actError" role="alert" class="text-[12.5px] text-destructive" data-testid="act-as-error">{{ actError }}</p>
            <AlertDialogFooter class="flex-row flex-wrap justify-end gap-2">
                <AlertDialogCancel :disabled="starting" class="mt-0">Cancel</AlertDialogCancel>
                <Button variant="destructive" :disabled="starting" data-testid="act-as-confirm" @click="actAs">{{ actLabel }}</Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
