<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

// One floating tooltip for a whole grid (roster-s3.html `.ctip`). A 20-week matrix has thousands of
// cells; a Tooltip component per cell would mount thousands of popovers. Any descendant of
// `container` with `data-tip` shows the tip on hover or keyboard focus; `resolve(key)` returns
// `{ title, rows: [[label, value, detail?]] }` for it.
const props = defineProps({
    container: { type: Object, default: null },
    resolve: { type: Function, required: true },
});

const tip = ref(null);
const content = ref(null);
const style = ref({});

function show(el) {
    content.value = props.resolve(el.dataset.tip);
    if (!content.value) return hide();

    requestAnimationFrame(() => {
        if (!tip.value) return;
        const rect = el.getBoundingClientRect();
        const width = tip.value.offsetWidth;
        const height = tip.value.offsetHeight;
        const left = Math.max(8, Math.min(rect.left + rect.width / 2 - width / 2, window.innerWidth - width - 8));
        let top = rect.top - height - 8;
        if (top < 56) top = rect.bottom + 8;
        style.value = { left: `${left}px`, top: `${top}px` };
    });
}

function hide() {
    content.value = null;
}

function onOver(event) {
    const el = event.target.closest?.('[data-tip]');
    el ? show(el) : hide();
}

let bound = null;
function bind(el) {
    if (bound) {
        for (const [type, fn] of [['mouseover', onOver], ['focusin', onOver], ['mouseleave', hide], ['focusout', hide], ['scroll', hide]]) {
            bound.removeEventListener(type, fn);
        }
    }
    bound = el;
    if (!el) return;
    el.addEventListener('mouseover', onOver);
    el.addEventListener('focusin', onOver);
    el.addEventListener('mouseleave', hide);
    el.addEventListener('focusout', hide);
    el.addEventListener('scroll', hide, { passive: true });
}

onMounted(() => bind(props.container));
watch(() => props.container, bind);
onBeforeUnmount(() => bind(null));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="content"
            ref="tip"
            role="tooltip"
            data-testid="roster-tip"
            class="pointer-events-none fixed z-[75] w-max max-w-72 rounded-md bg-foreground px-2.5 py-2 text-xs leading-snug text-background shadow-lg"
            :style="style"
        >
            <p class="font-medium">{{ content.title }}</p>
            <dl v-if="content.rows?.length" class="mt-1.5 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5">
                <template v-for="row in content.rows" :key="row[0]">
                    <dt class="opacity-70">{{ row[0] }}</dt>
                    <dd>
                        <span class="font-medium">{{ row[1] }}</span>
                        <span v-if="row[2]" class="block opacity-70">{{ row[2] }}</span>
                    </dd>
                </template>
            </dl>
        </div>
    </Teleport>
</template>
