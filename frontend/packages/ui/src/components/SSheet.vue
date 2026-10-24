<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'

/**
 * Painel modal: sobe de baixo no celular, centralizado no desktop.
 * Usa <dialog> nativo (foco preso, Esc fecha, fundo inerte).
 */
const props = defineProps<{ open: boolean; label: string }>()
const emit = defineEmits<{ close: [] }>()
const dialog = ref<HTMLDialogElement | null>(null)

watch(
  () => props.open,
  async (open) => {
    await nextTick()
    const el = dialog.value
    if (!el) return
    if (open && !el.open) {
      el.showModal?.()
      document.documentElement.style.overflow = 'hidden'
    }
    if (!open && el.open) {
      el.close?.()
      document.documentElement.style.overflow = ''
    }
  },
  { immediate: true },
)

onBeforeUnmount(() => {
  document.documentElement.style.overflow = ''
})

function onBackdrop(event: MouseEvent) {
  if (event.target === dialog.value) emit('close')
}
</script>

<template>
  <dialog
    ref="dialog"
    :aria-label="label"
    class="sheet m-0 mt-auto max-h-[92dvh] w-full max-w-none overflow-hidden rounded-t-2xl bg-surface p-0 text-ink sm:m-auto sm:max-w-lg sm:rounded-2xl"
    @cancel.prevent="emit('close')"
    @click="onBackdrop"
  >
    <div v-if="open" class="flex max-h-[92dvh] flex-col">
      <slot />
    </div>
  </dialog>
</template>

<style scoped>
.sheet::backdrop {
  background: rgb(28 25 23 / 0.55);
}
.sheet[open] {
  animation: sheet-in 220ms cubic-bezier(0.2, 0.8, 0.2, 1);
}
@keyframes sheet-in {
  from { transform: translateY(24px); opacity: 0; }
  to { transform: none; opacity: 1; }
}
@media (prefers-reduced-motion: reduce) {
  .sheet[open] { animation: none; }
}
</style>
