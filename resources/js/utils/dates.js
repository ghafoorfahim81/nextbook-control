import { isValidJalaaliDate, toGregorian, toJalaali } from 'jalaali-js'

/**
 * Dates are stored, sent and compared as Gregorian ISO strings (2026-11-03);
 * only what a person reads or types is Jalali. Persian and Pashto speakers
 * (the locales fa and ps) see the Solar Hijri calendar, with Persian digits;
 * English sees plain ISO.
 */

const DIGITS = '۰۱۲۳۴۵۶۷۸۹'

export const usesJalali = (locale) => locale === 'fa' || locale === 'ps'

const pad = (n) => String(n).padStart(2, '0')
const withDigits = (text) => text.replace(/\d/g, (d) => DIGITS[Number(d)])

/** '2026-11-03' → { jy: 1405, jm: 8, jd: 12 }, or null if it is not a date. */
export function toJalaliParts(iso) {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso ?? ''))
    if (!match) return null

    const [, y, m, d] = match.map(Number)
    // A real Gregorian day: the Date object rolls 2026-02-31 over into March, so a change means it was not real.
    const probe = new Date(Date.UTC(y, m - 1, d))
    if (probe.getUTCFullYear() !== y || probe.getUTCMonth() !== m - 1 || probe.getUTCDate() !== d) return null

    return toJalaali(y, m, d)
}

/** '1405/08/12' (any digits, any of / - .) → '2026-11-03', or null if it is not a real date. */
export function jalaliTextToIso(text) {
    const latin = String(text ?? '').replace(/[۰-۹]/g, (c) => DIGITS.indexOf(c)).replace(/[٠-٩]/g, (c) => '٠١٢٣٤٥٦٧٨٩'.indexOf(c))
    const match = /^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})$/.exec(latin.trim())
    if (!match) return null

    const [, jy, jm, jd] = match.map(Number)
    if (!isValidJalaaliDate(jy, jm, jd)) return null

    const { gy, gm, gd } = toGregorian(jy, jm, jd)

    return `${gy}-${pad(gm)}-${pad(gd)}`
}

/** A date for display: Jalali with Persian digits for fa and ps, ISO for en. */
export function formatDate(iso, locale = 'fa') {
    if (!iso) return ''
    if (!usesJalali(locale)) return String(iso).slice(0, 10)

    const parts = toJalaliParts(iso)

    return parts ? withDigits(`${parts.jy}/${pad(parts.jm)}/${pad(parts.jd)}`) : String(iso).slice(0, 10)
}

/** A timestamp for display: the date as above and the time as it came. */
export function formatDateTime(value, locale = 'fa') {
    if (!value) return ''
    const text = String(value).replace('T', ' ')
    const time = /\d{2}:\d{2}/.exec(text.slice(10))?.[0]
    const date = formatDate(text.slice(0, 10), locale)

    return time ? `${date} ${usesJalali(locale) ? withDigits(time) : time}` : date
}
