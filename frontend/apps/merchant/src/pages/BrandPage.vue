<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ApiError, catalogApi, describeError, type TenantSettings } from '@sertec/api-client'
import { SAlert, SButton, SPageState, STextArea, STextField } from '@sertec/ui'
import { notify } from '../notify'
import { can } from '../session'

const settings = ref<TenantSettings | null>(null)
const form = reactive({ description: '', color: '#c2410c', legal_name: '', cnpj: '', instagram: '', whatsapp: '' })
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const saving = ref(false)
const uploading = ref<'logo' | 'cover' | null>(null)
const error = ref<string | null>(null)
const fields = ref<Record<string, string[]>>({})
const manage = can('settings.update')

/** Tons com boa leitura de texto branco (AA), para quem não tem a cor exata. */
const swatches = [
  { hex: '#c2410c', name: 'Laranja' },
  { hex: '#b91c1c', name: 'Vermelho' },
  { hex: '#be185d', name: 'Rosa' },
  { hex: '#7e22ce', name: 'Roxo' },
  { hex: '#1d4ed8', name: 'Azul' },
  { hex: '#0f766e', name: 'Verde-água' },
  { hex: '#15803d', name: 'Verde' },
  { hex: '#78350f', name: 'Café' },
  { hex: '#292524', name: 'Grafite' },
]

/** Mesmo cálculo do servidor (WCAG): contraste do branco sobre a cor. */
function contrastWithWhite(hex: string): number {
  const n = parseInt(hex.slice(1), 16)
  const lin = [(n >> 16) & 255, (n >> 8) & 255, n & 255]
    .map((c) => c / 255)
    .map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4))
  const l = 0.2126 * lin[0]! + 0.7152 * lin[1]! + 0.0722 * lin[2]!
  return 1.05 / (l + 0.05)
}

const contrast = computed(() => contrastWithWhite(form.color))
const readable = computed(() => contrast.value >= 4.5)
const monogram = computed(() => (settings.value?.name ?? '').split(/\s+/).filter((w) => w.length > 2).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('') || 'S')

function fill(s: TenantSettings) {
  settings.value = s
  form.description = s.description ?? ''
  form.color = s.brand_color ?? '#c2410c'
  form.legal_name = s.legal_name ?? ''
  form.cnpj = s.cnpj ?? ''
  form.instagram = s.instagram ? `@${s.instagram}` : ''
  form.whatsapp = s.whatsapp ? formatPhone(s.whatsapp) : ''
}

function formatPhone(digits: string): string {
  const d = digits.startsWith('55') ? digits.slice(2) : digits
  return d.length === 11 ? `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}` : d.length === 10 ? `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}` : d
}

async function load() {
  state.value = 'loading'
  try {
    fill(await catalogApi.settings())
    state.value = 'ready'
  } catch (e) {
    state.value = e instanceof ApiError && e.code === 'network_error' ? 'offline' : 'error'
  }
}

const err = (k: string) => fields.value[k]?.[0] ?? null

async function save() {
  saving.value = true
  error.value = null
  fields.value = {}
  try {
    fill(await catalogApi.saveSettings({
      description: form.description.trim() || null,
      brand_color: form.color,
      legal_name: form.legal_name.trim() || null,
      cnpj: form.cnpj.trim() || null,
      instagram: form.instagram.trim() || null,
      whatsapp: form.whatsapp.trim() || null,
    }))
    notify('Marca atualizada')
  } catch (e) {
    const d = describeError(e)
    fields.value = d.fields ?? {}
    error.value = d.fields ? 'Confira os campos destacados.' : d.message
  } finally {
    saving.value = false
  }
}

async function onImage(kind: 'logo' | 'cover', event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  uploading.value = kind
  try {
    fill(await catalogApi.uploadBrandImage(kind, file))
    notify(kind === 'logo' ? 'Logo atualizada' : 'Capa atualizada')
  } catch (e) {
    notify(describeError(e).fields?.image?.[0] ?? describeError(e).message, 'error')
  } finally {
    uploading.value = null
  }
}

async function removeImage(kind: 'logo' | 'cover') {
  try {
    fill(await catalogApi.deleteBrandImage(kind))
    notify(kind === 'logo' ? 'Logo removida' : 'Capa removida')
  } catch (e) {
    notify(describeError(e).message, 'error')
  }
}

onMounted(load)
</script>

<template>
  <h1 class="mb-6 font-display text-2xl font-bold">Marca</h1>

  <SPageState v-if="state === 'loading'" state="loading" />
  <SPageState v-else-if="state === 'offline'" state="offline" @retry="load" />
  <SPageState v-else-if="state === 'error'" state="error" @retry="load" />
  <div v-else-if="settings" class="grid max-w-5xl gap-10 lg:grid-cols-[1fr_20rem]">
    <div class="grid content-start gap-10">
      <section aria-labelledby="imagens">
        <h2 id="imagens" class="mb-4 font-semibold">Logo e capa</h2>
        <div class="grid gap-6 sm:grid-cols-[auto_1fr]">
          <div class="grid content-start justify-items-start gap-2">
            <img v-if="settings.logo_url" :src="settings.logo_url" alt="Logo atual" class="size-24 rounded-[1.25rem] border border-line object-cover">
            <span v-else class="grid size-24 place-items-center rounded-[1.25rem] border border-dashed border-line text-xs text-muted">Sem logo</span>
            <label v-if="manage" class="cursor-pointer">
              <span class="inline-flex min-h-10 items-center rounded-(--radius-control) border border-line px-3 text-sm font-semibold hover:bg-canvas">
                {{ uploading === 'logo' ? 'Enviando…' : settings.logo_url ? 'Trocar logo' : 'Enviar logo' }}
              </span>
              <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" aria-label="Arquivo da logo" :disabled="uploading !== null" @change="onImage('logo', $event)">
            </label>
            <button v-if="manage && settings.logo_url" type="button" class="text-sm text-muted underline hover:text-ink" @click="removeImage('logo')">Remover logo</button>
          </div>
          <div class="grid content-start gap-2">
            <img v-if="settings.cover_url" :src="settings.cover_url" alt="Capa atual" class="aspect-[2.5/1] w-full rounded-xl border border-line object-cover">
            <span v-else class="grid aspect-[2.5/1] w-full place-items-center rounded-xl border border-dashed border-line text-xs text-muted">Sem capa: o topo usa a cor da marca</span>
            <div v-if="manage" class="flex flex-wrap items-center gap-3">
              <label class="cursor-pointer">
                <span class="inline-flex min-h-10 items-center rounded-(--radius-control) border border-line px-3 text-sm font-semibold hover:bg-canvas">
                  {{ uploading === 'cover' ? 'Enviando…' : settings.cover_url ? 'Trocar capa' : 'Enviar capa' }}
                </span>
                <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" aria-label="Arquivo da capa" :disabled="uploading !== null" @change="onImage('cover', $event)">
              </label>
              <button v-if="settings.cover_url" type="button" class="text-sm text-muted underline hover:text-ink" @click="removeImage('cover')">Remover capa</button>
            </div>
          </div>
        </div>
        <p class="mt-3 text-sm text-muted">JPG, PNG ou WebP até 5 MB. A logo é recortada em quadrado; a capa, em faixa larga (foto do salão, da vitrine ou do produto mais famoso).</p>
      </section>

      <form class="grid content-start gap-10" @submit.prevent="save">
        <section aria-labelledby="visual" class="grid gap-5">
          <h2 id="visual" class="font-semibold">Cor e apresentação</h2>
          <SAlert v-if="error">{{ error }}</SAlert>
          <fieldset class="grid gap-2">
            <legend class="mb-1.5 text-sm font-medium">Cor principal</legend>
            <div class="flex flex-wrap items-center gap-2">
              <button
                v-for="s in swatches"
                :key="s.hex"
                type="button"
                class="size-9 rounded-full ring-offset-2 transition"
                :class="form.color === s.hex ? 'ring-2 ring-ink' : 'hover:scale-110'"
                :style="{ background: s.hex }"
                :aria-label="s.name"
                :aria-pressed="form.color === s.hex"
                :disabled="!manage"
                @click="form.color = s.hex"
              />
              <label class="ml-1 inline-flex items-center gap-2 text-sm">
                <input v-model="form.color" type="color" class="h-9 w-12 cursor-pointer rounded-lg border border-line bg-surface" aria-label="Outra cor" :disabled="!manage">
                <code>{{ form.color }}</code>
              </label>
            </div>
            <p class="text-sm" :class="readable ? 'text-success' : 'text-danger'">
              Contraste com texto branco: {{ contrast.toFixed(1) }}:1
              {{ readable ? '— boa leitura' : '— clara demais; escolha um tom mais escuro (mínimo 4,5:1)' }}
            </p>
          </fieldset>
          <STextArea v-model="form.description" label="Descrição no topo do cardápio" :maxlength="1000" hint="Uma frase que diga o que torna o seu lugar especial." />
        </section>

        <section aria-labelledby="empresa" class="grid gap-5">
          <div>
            <h2 id="empresa" class="font-semibold">Dados da empresa e contato</h2>
            <p class="mt-1 text-sm text-muted">Razão social e CNPJ aparecem no rodapé do cardápio, como pede a lei do comércio eletrônico.</p>
          </div>
          <div class="grid gap-5 sm:grid-cols-2">
            <STextField v-model="form.legal_name" label="Razão social" placeholder="Ex.: Yris Doces LTDA" :error="err('legal_name')" />
            <STextField v-model="form.cnpj" label="CNPJ" placeholder="00.000.000/0000-00" :error="err('cnpj')" />
            <STextField v-model="form.instagram" label="Instagram" placeholder="@sualoja" :error="err('instagram')" />
            <STextField v-model="form.whatsapp" label="WhatsApp da loja" type="tel" placeholder="(79) 99999-0000" :error="err('whatsapp')" />
          </div>
          <p class="text-sm text-muted">Sem WhatsApp aqui, o botão do cardápio usa o telefone da unidade. Nome e endereço do cardápio ({{ settings.slug }}) são alterados pela equipe SerTec.</p>
        </section>

        <div v-if="manage"><SButton type="submit" :loading="saving" :disabled="!readable">Salvar marca</SButton></div>
      </form>
    </div>

    <figure aria-label="Prévia do topo do cardápio" class="h-fit overflow-hidden rounded-2xl border border-line bg-surface lg:sticky lg:top-6">
      <img v-if="settings.cover_url" :src="settings.cover_url" alt="" class="aspect-[2.5/1] w-full object-cover">
      <div v-else class="h-20" :style="{ background: form.color, backgroundImage: 'radial-gradient(circle at 1px 1px, rgb(255 255 255 / 0.14) 1px, transparent 0)', backgroundSize: '12px 12px' }" />
      <div class="px-4 pb-5">
        <img v-if="settings.logo_url" :src="settings.logo_url" alt="" class="-mt-8 size-16 rounded-2xl object-cover ring-4 ring-surface">
        <span v-else class="-mt-8 grid size-16 place-items-center rounded-2xl font-display text-2xl font-extrabold text-white ring-4 ring-surface" :style="{ background: form.color }">{{ monogram }}</span>
        <p class="mt-3 font-display text-2xl leading-none font-extrabold">{{ settings.name }}</p>
        <p v-if="form.description" class="mt-2 line-clamp-3 text-sm text-muted">{{ form.description }}</p>
        <span class="mt-4 inline-block rounded-full px-3 py-1.5 text-xs font-semibold text-white" :style="{ background: form.color }">Adicionar · R$ 25,00</span>
      </div>
      <figcaption class="border-t border-line px-4 py-2.5 text-xs text-muted">Prévia do cardápio</figcaption>
    </figure>
  </div>
</template>
