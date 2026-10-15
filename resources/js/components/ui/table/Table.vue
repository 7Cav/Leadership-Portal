<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"
import { vStickyHead } from "@/lib/stickyHead"

// Portal addition: `sticky-header` keeps the header row in view on a long table (see
// lib/stickyHead.js); `sticky-header="box"` always scrolls the table in its own capped box.
const props = defineProps<{
  class?: HTMLAttributes["class"]
  stickyHeader?: boolean | "box"
}>()
</script>

<template>
  <div v-sticky-head="props.stickyHeader ?? false" data-slot="table-container" class="relative w-full overflow-auto">
    <table data-slot="table" :class="cn('w-full caption-bottom text-sm', props.class)">
      <slot />
    </table>
  </div>
</template>
