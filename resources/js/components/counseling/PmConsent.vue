<script setup>
import { PhEnvelopeSimple, PhWarning } from '@phosphor-icons/vue';
import InlineNotice from '@/components/InlineNotice.vue';
import { Button } from '@/components/ui/button';

// The PMs of the counseling procedure go from the leader's (or trooper's) own forum account (#565). Until the forum
// lets the portal send them, a step that sends one asks for that here: `consentUrl` is the forum's consent screen, a
// full-page visit (it leaves the portal), which comes back to this page. `pm` is the forum's refusal of a PM after
// consent, with the record left unchanged.
defineProps({
    // `{ allowed, consentUrl }` from the page.
    pm: { type: Object, required: true },
    // A `pm_consent` error from the last request: the grant expired or was revoked.
    consentError: { type: String, default: null },
    // A `pm` error from the last request: the forum did not take the PM.
    pmError: { type: String, default: null },
    // What the step sends, for the sentence: "the meeting invite", "the After-Action PM".
    sends: { type: String, default: 'this PM' },
    // Off when the step on screen sends no PM right now (a trooper not appealing): only a refusal shows then.
    asks: { type: Boolean, default: true },
});
</script>

<template>
    <InlineNotice v-if="(asks && !pm.allowed) || consentError" :icon="PhEnvelopeSimple" tone="warning" role="status" data-testid="pm-consent">
        <p>
            {{ consentError ?? `The portal sends ${sends} from your own forum account, so it needs your permission first.` }}
        </p>
        <Button as-child size="sm" variant="outline" class="mt-2">
            <!-- A full-page visit, not an Inertia one: it goes to the forum and comes back here. -->
            <a :href="pm.consentUrl" data-testid="pm-consent-link">Allow PMs from your forum account</a>
        </Button>
    </InlineNotice>
    <InlineNotice v-if="pmError" :icon="PhWarning" tone="warning" role="alert" data-testid="pm-error">
        {{ pmError }} The record is unchanged; try again in a moment.
    </InlineNotice>
</template>
