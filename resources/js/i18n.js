import fa from './lang/fa'
import en from './lang/en'

const dictionaries = { fa, en }

/**
 * A small translator: t('customers.title'), t('days_left', { n: 5 }).
 * The locale is the one the server chose for the page (Persian by default), so
 * it never changes while a page is open.
 */
export const i18n = {
    appName: 'NextBook Control',
    locale: 'fa',

    setLocale(locale) {
        this.locale = dictionaries[locale] ? locale : 'fa'
    },

    t(key, params = {}) {
        const text = key.split('.').reduce((node, part) => node?.[part], dictionaries[this.locale])
            ?? key.split('.').reduce((node, part) => node?.[part], dictionaries.en)
            ?? key

        return typeof text === 'string'
            ? text.replace(/\{(\w+)\}/g, (_, name) => params[name] ?? `{${name}}`)
            : key
    },
}

export const t = (key, params) => i18n.t(key, params)
