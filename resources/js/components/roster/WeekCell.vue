<script setup>
// One Cav week as a split cell (roster-s3.html `.cc`): top half the rollcall signature, bottom
// half the section practice. Missed and unsigned carry a slash and LOA a hatch, so the state never
// rests on colour alone. Section aggregate rows pass heat levels `g1`–`g4` instead. Without `rc`
// only the SP half renders (the rollcall card's attendance strip).
defineProps({
    rc: { type: String, default: null },
    sp: { type: String, required: true },
    size: { type: String, default: 'md' },
    auto: { type: Boolean, default: false },
});
</script>

<template>
    <span class="roster-cc" :class="[`roster-cc--${size}`, { 'roster-cc--auto': auto }]">
        <span v-if="rc" class="roster-h" :class="`roster-h--${rc}`" />
        <span class="roster-h" :class="`roster-h--${sp}`" />
    </span>
</template>

<style>
.roster-cc {
    display: inline-flex;
    flex-direction: column;
    gap: 2px;
    width: 1.75rem;
    position: relative;
    border-radius: 4px;
    vertical-align: middle;
}
.roster-cc--sm {
    width: 0.625rem;
    gap: 1px;
}
.roster-cc--sm .roster-h {
    height: 0.4375rem;
    border-radius: 2px;
}
.roster-cc--xs {
    width: 0.8125rem;
}
.roster-h {
    display: block;
    height: 0.8125rem;
    border-radius: 3px;
    box-sizing: border-box;
}
.roster-h--ok,
.roster-h--g4 {
    background: var(--success);
}
.roster-h--g3 {
    background: color-mix(in oklab, var(--success) 45%, transparent);
}
.roster-h--mk {
    background: repeating-linear-gradient(135deg, var(--success) 0 2.5px, color-mix(in oklab, var(--success) 32%, transparent) 2.5px 5px);
}
.roster-h--at {
    background: var(--info);
}
.roster-h--cn,
.roster-h--g2 {
    background: var(--warning);
}
.roster-h--bad,
.roster-h--g1 {
    background:
        linear-gradient(to top right, transparent calc(50% - 1px), var(--card) calc(50% - 1px) calc(50% + 0.6px), transparent calc(50% + 0.6px)),
        var(--destructive);
}
.roster-h--nil {
    background: color-mix(in oklab, var(--muted-foreground) 18%, transparent);
}
.roster-h--open,
.roster-h--pending {
    border: 1px dashed color-mix(in oklab, var(--muted-foreground) 75%, transparent);
}
.roster-h--loa {
    background: repeating-linear-gradient(135deg, color-mix(in oklab, var(--muted-foreground) 55%, transparent) 0 1.5px, transparent 1.5px 4.5px);
    box-shadow: inset 0 0 0 1px color-mix(in oklab, var(--muted-foreground) 35%, transparent);
}
.roster-h--out {
    background: linear-gradient(var(--border), var(--border)) center / 0.5rem 1px no-repeat;
}
.roster-cc--auto::after {
    content: '';
    position: absolute;
    right: -3px;
    top: -3px;
    border-left: 6px solid transparent;
    border-top: 6px solid var(--foreground);
}
</style>
