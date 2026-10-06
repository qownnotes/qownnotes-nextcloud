/**
 * SPDX-FileCopyrightText: 2026 Patrizio Bekerle <patrizio@bekerle.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import router from './router.js'

const app = createApp(App)
app.use(createPinia())
app.use(router)
// NcContent replaces the content area of the Nextcloud page
app.mount('#content')
