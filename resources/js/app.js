import './bootstrap'
import '../css/app.css'

import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { i18n } from './i18n'

createInertiaApp({
    title: (title) => (title ? `${title} — ${i18n.appName}` : i18n.appName),
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
        return pages[`./Pages/${name}.vue`]
    },
    setup({ el, App, props, plugin }) {
        i18n.setLocale(props.initialPage.props.locale)

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },
})
