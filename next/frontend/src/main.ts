import { createApp } from 'vue'
import App from './App.vue'
import './style.css'

const root = document.getElementById('go-vue-preview')
if (root) createApp(App, { endpoint: root.dataset.endpoint || 'api/go-vue-families.php' }).mount(root)
