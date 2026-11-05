import { createApp } from 'vue'
import { enableHoverHints } from '@sertec/ui'
import App from './App.vue'
import { router } from './router'
import './style.css'

createApp(App).use(router).mount('#app')
// Texto cortado e botões só com ícone mostram a informação completa ao passar o mouse.
enableHoverHints()
