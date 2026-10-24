import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// Em dev o Nginx (docker compose) serve tudo na mesma origem do tenant:
// /api -> Laravel, /painel/ -> este servidor Vite. HMR passa pelo Nginx (porta 80).
export default defineConfig({
  base: '/painel/',
  plugins: [vue(), tailwindcss()],
  server: {
    host: true,
    port: 5174,
    strictPort: true,
    allowedHosts: ['.cardapios.localhost'],
    hmr: { clientPort: 80 },
  },
})
