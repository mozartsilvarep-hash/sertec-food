<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { adminTenantsApi, describeError, type AdminTenant } from '@sertec/api-client'
import { SAlert, SButton, STextField } from '@sertec/ui'
import { slugify } from '../labels'

const form = reactive({ name: '', slug: '', owner_name: '', owner_email: '', plan: '', timezone: 'America/Maceio', activate: true })
const plans = ref<{ slug: string; name: string }[]>([])
const errors = ref<Record<string, string[]>>({})
const message = ref<string | null>(null)
const saving = ref(false)
const created = ref<AdminTenant | null>(null)
const slugTouched = ref(false)
const base = import.meta.env.VITE_TENANCY_BASE_DOMAIN ?? window.location.hostname.replace(/^admin\./, '')

watch(() => form.name, (name) => {
  if (!slugTouched.value) form.slug = slugify(name)
})

async function save() {
  saving.value = true
  errors.value = {}
  message.value = null
  try {
    created.value = await adminTenantsApi.create({ ...form, plan: form.plan || null })
  } catch (e) {
    const d = describeError(e)
    errors.value = d.fields ?? {}
    message.value = d.fields ? 'Confira os campos destacados.' : d.message
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  plans.value = await adminTenantsApi.plans().catch(() => [])
})
</script>

<template>
  <RouterLink to="/" class="text-sm text-brand-700 hover:underline">‹ Estabelecimentos</RouterLink>
  <h1 class="mt-2 mb-6 text-2xl font-bold">Novo estabelecimento</h1>

  <section v-if="created" class="max-w-2xl rounded-(--radius-card) border-2 border-success bg-surface p-5" aria-live="polite">
    <h2 class="text-lg font-bold">{{ created.name }} criado</h2>
    <dl class="mt-4 grid gap-2 text-sm">
      <div><dt class="text-muted">Cardápio</dt><dd><a :href="`http://${created.host}`" class="font-semibold text-brand-700 underline" target="_blank" rel="noopener">{{ created.host }}</a></dd></div>
      <div><dt class="text-muted">Painel do restaurante</dt><dd class="font-semibold">{{ created.host }}/painel</dd></div>
      <div><dt class="text-muted">Login do proprietário</dt><dd class="font-semibold">{{ created.owner?.email }}</dd></div>
      <div v-if="created.owner_password">
        <dt class="text-muted">Senha temporária (aparece só agora)</dt>
        <dd><code class="mt-1 inline-block rounded-lg bg-canvas px-3 py-2 text-base font-bold tracking-wider">{{ created.owner_password }}</code></dd>
      </div>
      <p v-else class="text-muted">Este e-mail já tinha conta: o proprietário usa a senha que já conhece.</p>
    </dl>
    <p class="mt-4 text-sm text-muted">Envie a senha por um canal seguro e peça para trocá-la em “Minha conta” no primeiro acesso.</p>
    <RouterLink :to="`/estabelecimentos/${created.id}`" class="mt-4 inline-block font-semibold text-brand-700 hover:underline">Abrir ficha do estabelecimento ›</RouterLink>
  </section>

  <form v-else class="grid max-w-2xl gap-5" novalidate @submit.prevent="save">
    <SAlert v-if="message">{{ message }}</SAlert>
    <STextField v-model="form.name" label="Nome do estabelecimento" :error="errors.name?.[0] ?? null" />
    <div class="grid gap-1.5">
      <STextField v-model="form.slug" label="Endereço do cardápio" :error="errors.slug?.[0] ?? null" @input="slugTouched = true" />
      <p class="text-sm text-muted">{{ form.slug || 'endereco' }}.{{ base }}</p>
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
      <STextField v-model="form.owner_name" label="Nome do proprietário" :error="errors.owner_name?.[0] ?? null" />
      <STextField v-model="form.owner_email" label="E-mail do proprietário" type="email" :error="errors.owner_email?.[0] ?? null" />
    </div>
    <div class="grid gap-4 sm:grid-cols-2">
      <label class="grid gap-1.5 text-sm font-medium">Plano
        <select v-model="form.plan" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
          <option value="">Sem plano</option>
          <option v-for="p in plans" :key="p.slug" :value="p.slug">{{ p.name }}</option>
        </select>
      </label>
      <label class="grid gap-1.5 text-sm font-medium">Fuso horário
        <select v-model="form.timezone" class="min-h-11 rounded-(--radius-control) border border-line bg-surface px-3">
          <option value="America/Maceio">Sergipe e Alagoas (Aracaju, Maceió)</option>
          <option value="America/Recife">Recife</option>
          <option value="America/Fortaleza">Fortaleza</option>
          <option value="America/Bahia">Bahia</option>
          <option value="America/Sao_Paulo">Brasília/São Paulo</option>
          <option value="America/Manaus">Manaus</option>
        </select>
      </label>
    </div>
    <label class="inline-flex items-center gap-2 text-sm"><input v-model="form.activate" type="checkbox" class="accent-brand-600"> Ativar agora (o cardápio já abre ao público)</label>
    <div><SButton type="submit" :loading="saving">Criar estabelecimento</SButton></div>
  </form>
</template>
