<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ApiError, catalogApi, describeError, type BrandImageKind, type StoreThemeInput, type TenantSettings } from '@sertec/api-client'
import {
  DEFAULT_THEME_COLORS, fontsFor, fontStack, loadThemeFonts, RADII, SAlert, SButton, SPageState, STextArea, STextField,
  themeContrastIssues, type ThemeColorKey, type ThemeRadius,
} from '@sertec/ui'
import { notify } from '../notify'
import { can } from '../session'

const settings = ref<TenantSettings | null>(null)
const form = reactive({ description: '', color: '#c2410c', legal_name: '', cnpj: '', instagram: '', whatsapp: '' })
const state = ref<'loading' | 'ready' | 'error' | 'offline'>('loading')
const saving = ref(false)
const uploading = ref<BrandImageKind | null>(null)
const error = ref<string | null>(null)
const fields = ref<Record<string, string[]>>({})
const manage = can('settings.update')

/* ---------- Tema da loja (cores de fundo e texto, fontes, cantos) ---------- */
const theme = reactive({
  colors: { ...DEFAULT_THEME_COLORS } as Record<ThemeColorKey, string | null>,
  fonts: { display: 'bricolage-grotesque', body: 'system', script: null as string | null },
  radius: 'soft' as ThemeRadius,
})
const colorFields: { key: ThemeColorKey; label: string; hint: string }[] = [
  { key: 'canvas', label: 'Fundo', hint: 'Fundo da página' },
  { key: 'surface', label: 'Cartões', hint: 'Produtos, carrinho, formulários' },
  { key: 'ink', label: 'Texto', hint: 'Títulos e textos' },
  { key: 'muted', label: 'Texto suave', hint: 'Descrições e detalhes' },
  { key: 'line', label: 'Linhas', hint: 'Bordas e divisões' },
  { key: 'accent', label: 'Destaque do topo', hint: 'Faixa do topo sem capa (vazio = cor principal)' },
]
const displayFonts = fontsFor('display')
const bodyFonts = fontsFor('body')
const scriptFonts = fontsFor('script')
const themeIssues = computed(() => themeContrastIssues(theme.colors))
const themeOk = computed(() => Object.keys(themeIssues.value).length === 0)

/** Modelos prontos: ponto de partida, tudo pode ser ajustado depois. */
const presets: { name: string; brand: string; theme: StoreThemeInput }[] = [
  { name: 'Padrão SerTec', brand: '#c2410c', theme: { colors: { ...DEFAULT_THEME_COLORS }, fonts: { display: 'bricolage-grotesque', body: 'system', script: null }, radius: 'soft' } },
  { name: 'Confeitaria', brand: '#4a2c23', theme: { colors: { canvas: '#fff7ee', surface: '#ffffff', ink: '#4a2c23', muted: '#6b4c40', line: '#e9d9c9', accent: '#a9cdee' }, fonts: { display: 'gilda-display', body: 'jost', script: 'great-vibes' }, radius: 'round' } },
  { name: 'Pizzaria', brand: '#b91c1c', theme: { colors: { canvas: '#fdf8f0', surface: '#ffffff', ink: '#2b1d14', muted: '#5c4a3d', line: '#eadfce', accent: '#14532d' }, fonts: { display: 'dm-serif-display', body: 'lato', script: null }, radius: 'soft' } },
  { name: 'Hamburgueria', brand: '#b45309', theme: { colors: { canvas: '#f5f5f4', surface: '#ffffff', ink: '#0c0a09', muted: '#44403c', line: '#d6d3d1', accent: '#1c1917' }, fonts: { display: 'bebas-neue', body: 'inter', script: null }, radius: 'square' } },
  { name: 'Natural', brand: '#166534', theme: { colors: { canvas: '#f7f6ef', surface: '#ffffff', ink: '#1f2a1c', muted: '#4b5a45', line: '#dfe3d3', accent: '#a3b18a' }, fonts: { display: 'fraunces', body: 'nunito', script: null }, radius: 'round' } },
]

function fillTheme(t: StoreThemeInput) {
  for (const key of Object.keys(DEFAULT_THEME_COLORS) as ThemeColorKey[]) {
    theme.colors[key] = t.colors?.[key] ?? DEFAULT_THEME_COLORS[key]
  }
  theme.fonts.display = t.fonts?.display ?? 'bricolage-grotesque'
  theme.fonts.body = t.fonts?.body ?? 'system'
  theme.fonts.script = t.fonts?.script ?? null
  theme.radius = (t.radius ?? 'soft') as ThemeRadius
}

function usePreset(p: (typeof presets)[number]) {
  form.color = p.brand
  fillTheme(p.theme)
}

// Prévia com as fontes de verdade: baixa só as escolhidas.
watch(() => ({ ...theme.fonts }), (fonts) => void loadThemeFonts({ fonts }), { immediate: true })

const preview = computed(() => ({
  background: theme.colors.canvas ?? DEFAULT_THEME_COLORS.canvas!,
  color: theme.colors.ink ?? DEFAULT_THEME_COLORS.ink!,
  fontFamily: fontStack(theme.fonts.body) ?? undefined,
}))
const previewDisplay = computed(() => fontStack(theme.fonts.script) ?? fontStack(theme.fonts.display) ?? '"Bricolage Grotesque Variable", sans-serif')
const previewRadius = computed(() => RADII[theme.radius])

/* ---------- Importar do site ---------- */
const importOrigin = ref('')
const importing = ref(false)
const importWarnings = ref<string[]>([])

async function importFromSite() {
  if (!importOrigin.value) return
  importing.value = true
  importWarnings.value = []
  try {
    const imported = await catalogApi.importTheme(importOrigin.value)
    if (imported.brand_color) form.color = imported.brand_color
    fillTheme({ ...imported.theme, colors: { ...DEFAULT_THEME_COLORS, ...imported.theme.colors } })
    importWarnings.value = imported.warnings
    notify('Tema importado: confira a prévia e salve')
  } catch (e) {
    const d = describeError(e)
    notify(d.fields?.origin?.[0] ?? d.message, 'error')
  } finally {
    importing.value = false
  }
}

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
  fillTheme(s.theme)
  importOrigin.value = s.site_origins[0] ?? ''
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
      theme: {
        colors: { ...theme.colors },
        fonts: { ...theme.fonts },
        radius: theme.radius,
      },
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

const imageNames: Record<BrandImageKind, string> = { logo: 'Logo', cover: 'Capa', pattern: 'Padronagem' }

async function onImage(kind: BrandImageKind, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  uploading.value = kind
  try {
    fill(await catalogApi.uploadBrandImage(kind, file))
    notify(`${imageNames[kind]} atualizada`)
  } catch (e) {
    notify(describeError(e).fields?.image?.[0] ?? describeError(e).message, 'error')
  } finally {
    uploading.value = null
  }
}

async function removeImage(kind: BrandImageKind) {
  try {
    fill(await catalogApi.deleteBrandImage(kind))
    notify(`${imageNames[kind]} removida`)
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

        <div class="mt-6 grid gap-3 sm:grid-cols-[auto_1fr] sm:items-center">
          <span
            class="size-24 rounded-xl border border-line"
            :class="settings.pattern_url ? '' : 'grid place-items-center border-dashed text-xs text-muted'"
            :style="settings.pattern_url ? { background: `${theme.colors.accent ?? form.color} url('${settings.pattern_url}') 0 0 / 48px 48px repeat` } : {}"
          >{{ settings.pattern_url ? '' : 'Sem padronagem' }}</span>
          <div class="grid gap-2">
            <p class="text-sm"><strong class="font-semibold">Padronagem do topo</strong> <span class="text-muted">(opcional): um desenho que se repete na faixa do topo quando não há capa, como os cupcakes do site da confeitaria. Use um PNG quadrado com fundo transparente.</span></p>
            <div v-if="manage" class="flex flex-wrap items-center gap-3">
              <label class="cursor-pointer">
                <span class="inline-flex min-h-10 items-center rounded-(--radius-control) border border-line px-3 text-sm font-semibold hover:bg-canvas">
                  {{ uploading === 'pattern' ? 'Enviando…' : settings.pattern_url ? 'Trocar padronagem' : 'Enviar padronagem' }}
                </span>
                <input type="file" accept="image/png,image/webp" class="sr-only" aria-label="Arquivo da padronagem" :disabled="uploading !== null" @change="onImage('pattern', $event)">
              </label>
              <button v-if="settings.pattern_url" type="button" class="text-sm text-muted underline hover:text-ink" @click="removeImage('pattern')">Remover padronagem</button>
            </div>
          </div>
        </div>
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

        <section aria-labelledby="tema" class="grid gap-6">
          <div>
            <h2 id="tema" class="font-semibold">Tema da loja</h2>
            <p class="mt-1 text-sm text-muted">Cores de fundo e texto, fontes e cantos do cardápio. Deixe a loja com a cara do seu site.</p>
          </div>

          <div class="grid gap-3 rounded-xl border border-line p-4">
            <p class="text-sm font-medium">Importar do site</p>
            <p class="text-sm text-muted">Lê o arquivo <code>tema.json</code> publicado no seu site e preenche tudo aqui. Nada é salvo até você conferir a prévia e clicar em "Salvar marca".</p>
            <div v-if="settings.site_origins.length" class="flex flex-wrap items-center gap-2">
              <label class="text-sm">
                <span class="sr-only">Site</span>
                <select v-model="importOrigin" class="min-h-10 rounded-(--radius-control) border border-line bg-surface px-3">
                  <option v-for="o in settings.site_origins" :key="o" :value="o">{{ o }}</option>
                </select>
              </label>
              <SButton type="button" variant="secondary" :loading="importing" :disabled="!manage" @click="importFromSite">Importar tema</SButton>
            </div>
            <p v-else class="text-sm text-muted">Cadastre o endereço do site em <strong>Promoções › Vitrine e aviso › Sites autorizados</strong> para importar.</p>
            <ul v-if="importWarnings.length" class="list-disc pl-5 text-sm text-warning">
              <li v-for="w in importWarnings" :key="w">{{ w }}</li>
            </ul>
          </div>

          <fieldset class="grid gap-2">
            <legend class="mb-1.5 text-sm font-medium">Modelos prontos</legend>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="p in presets"
                :key="p.name"
                type="button"
                class="inline-flex min-h-10 items-center gap-2 rounded-full border border-line px-3 text-sm font-semibold hover:bg-canvas"
                :disabled="!manage"
                @click="usePreset(p)"
              >
                <span class="size-4 rounded-full ring-1 ring-black/10" :style="{ background: p.theme.colors?.canvas ?? '#fff' }" aria-hidden="true" />
                <span class="size-4 rounded-full" :style="{ background: p.brand }" aria-hidden="true" />
                {{ p.name }}
              </button>
            </div>
          </fieldset>

          <fieldset class="grid gap-3">
            <legend class="mb-1.5 text-sm font-medium">Cores</legend>
            <div class="grid gap-3 sm:grid-cols-2">
              <div v-for="f in colorFields" :key="f.key" class="flex items-start gap-3">
                <input
                  type="color"
                  class="h-10 w-12 shrink-0 cursor-pointer rounded-lg border border-line bg-surface"
                  :value="theme.colors[f.key] ?? form.color"
                  :aria-label="f.label"
                  :disabled="!manage"
                  @input="theme.colors[f.key] = ($event.target as HTMLInputElement).value"
                >
                <div class="min-w-0 text-sm">
                  <p class="font-medium">
                    {{ f.label }} <code class="text-xs text-muted">{{ theme.colors[f.key] ?? 'cor principal' }}</code>
                    <button v-if="f.key === 'accent' && theme.colors.accent" type="button" class="ml-1 text-xs text-muted underline" @click="theme.colors.accent = null">usar a principal</button>
                  </p>
                  <p class="text-muted">{{ f.hint }}</p>
                  <p v-if="themeIssues[f.key] || err(`theme.colors.${f.key}`)" class="text-danger">{{ themeIssues[f.key] ?? err(`theme.colors.${f.key}`) }}</p>
                </div>
              </div>
            </div>
          </fieldset>

          <div class="grid gap-4 sm:grid-cols-3">
            <label class="grid gap-1.5 text-sm">
              <span class="font-medium">Fonte dos títulos</span>
              <select v-model="theme.fonts.display" class="min-h-10 rounded-(--radius-control) border border-line bg-surface px-3" :disabled="!manage">
                <option v-for="f in displayFonts" :key="f.key" :value="f.key">{{ f.label }}</option>
              </select>
            </label>
            <label class="grid gap-1.5 text-sm">
              <span class="font-medium">Fonte do texto</span>
              <select v-model="theme.fonts.body" class="min-h-10 rounded-(--radius-control) border border-line bg-surface px-3" :disabled="!manage">
                <option v-for="f in bodyFonts" :key="f.key" :value="f.key">{{ f.label }}</option>
              </select>
            </label>
            <label class="grid gap-1.5 text-sm">
              <span class="font-medium">Nome da loja em letra cursiva</span>
              <select v-model="theme.fonts.script" class="min-h-10 rounded-(--radius-control) border border-line bg-surface px-3" :disabled="!manage">
                <option :value="null">Não (usa a fonte dos títulos)</option>
                <option v-for="f in scriptFonts" :key="f.key" :value="f.key">{{ f.label }}</option>
              </select>
            </label>
          </div>

          <fieldset class="grid gap-2">
            <legend class="mb-1.5 text-sm font-medium">Cantos de botões e cartões</legend>
            <div class="flex flex-wrap gap-2">
              <label
                v-for="(r, key) in RADII"
                :key="key"
                class="inline-flex min-h-10 cursor-pointer items-center gap-2 border border-line px-4 text-sm font-medium has-[:checked]:border-ink has-[:checked]:bg-canvas"
                :style="{ borderRadius: r.control }"
              >
                <input v-model="theme.radius" type="radio" name="radius" :value="key" class="sr-only" :disabled="!manage">
                {{ r.label }}
              </label>
            </div>
          </fieldset>
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

        <div v-if="manage"><SButton type="submit" :loading="saving" :disabled="!readable || !themeOk">Salvar marca</SButton></div>
      </form>
    </div>

    <figure aria-label="Prévia do topo do cardápio" class="h-fit overflow-hidden rounded-2xl border border-line lg:sticky lg:top-6" :style="preview">
      <img v-if="settings.cover_url" :src="settings.cover_url" alt="" class="aspect-[2.5/1] w-full object-cover">
      <div
        v-else
        class="h-20"
        :style="{
          backgroundColor: theme.colors.accent ?? form.color,
          backgroundImage: settings.pattern_url ? `url('${settings.pattern_url}')` : 'radial-gradient(circle at 1px 1px, rgb(255 255 255 / 0.14) 1px, transparent 0)',
          backgroundSize: settings.pattern_url ? '80px 80px' : '12px 12px',
        }"
      />
      <div class="px-4 pb-5">
        <img v-if="settings.logo_url" :src="settings.logo_url" alt="" class="-mt-8 size-16 rounded-2xl object-cover" :style="{ boxShadow: `0 0 0 4px ${preview.background}` }">
        <span v-else class="-mt-8 grid size-16 place-items-center rounded-2xl text-2xl font-extrabold text-white" :style="{ background: form.color, boxShadow: `0 0 0 4px ${preview.background}`, fontFamily: previewDisplay }">{{ monogram }}</span>
        <p class="mt-3 text-[1.75rem] leading-tight" :style="{ fontFamily: previewDisplay, fontWeight: theme.fonts.script ? 400 : 800 }">{{ settings.name }}</p>
        <p v-if="form.description" class="mt-2 line-clamp-3 text-sm" :style="{ color: theme.colors.muted ?? undefined }">{{ form.description }}</p>
        <div class="mt-4 border p-3" :style="{ background: theme.colors.surface ?? '#fff', borderColor: theme.colors.line ?? undefined, borderRadius: previewRadius.card }">
          <p class="font-semibold">Bolo de festa</p>
          <p class="text-xs" :style="{ color: theme.colors.muted ?? undefined }">Massa de baunilha, recheio de brigadeiro</p>
          <span class="mt-3 inline-block px-3 py-1.5 text-xs font-semibold text-white" :style="{ background: form.color, borderRadius: previewRadius.control }">Adicionar · R$ 25,00</span>
        </div>
      </div>
      <figcaption class="border-t px-4 py-2.5 text-xs" :style="{ borderColor: theme.colors.line ?? undefined, color: theme.colors.muted ?? undefined }">Prévia do cardápio</figcaption>
    </figure>
  </div>
</template>
