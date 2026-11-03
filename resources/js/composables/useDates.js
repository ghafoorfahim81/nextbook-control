import { i18n } from '../i18n'
import { formatDate, formatDateTime } from '../utils/dates'

/** Dates for display in the page's locale: Jalali for Persian and Pashto, ISO for English. */
export function useDates() {
    return {
        fd: (value) => formatDate(value, i18n.locale),
        fdt: (value) => formatDateTime(value, i18n.locale),
    }
}
