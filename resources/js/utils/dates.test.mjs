// Run with: npm test   (Node's own test runner; nothing to install)
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { formatDate, formatDateTime, jalaliTextToIso, toJalaliParts, usesJalali } from './dates.js'

test('known Gregorian dates convert to the right Jalali ones', () => {
    // 1405/08/02 is 2026-10-24 (the date the project docs are written on), and Nowruz 1405 is 2026-03-21.
    assert.deepEqual(toJalaliParts('2026-10-24'), { jy: 1405, jm: 8, jd: 2 })
    assert.deepEqual(toJalaliParts('2026-03-21'), { jy: 1405, jm: 1, jd: 1 })
    assert.deepEqual(toJalaliParts('2026-11-03'), { jy: 1405, jm: 8, jd: 12 })
})

test('the year boundaries and a leap day are right', () => {
    assert.deepEqual(toJalaliParts('2026-03-20'), { jy: 1404, jm: 12, jd: 29 })
    assert.deepEqual(toJalaliParts('2025-03-20'), { jy: 1403, jm: 12, jd: 30 }) // 1403 is a leap year
    assert.deepEqual(toJalaliParts('2025-03-21'), { jy: 1404, jm: 1, jd: 1 })
})

test('a Jalali text converts back to ISO, whatever the digits and separators', () => {
    assert.equal(jalaliTextToIso('1405/08/12'), '2026-11-03')
    assert.equal(jalaliTextToIso('۱۴۰۵/۰۸/۱۲'), '2026-11-03')
    assert.equal(jalaliTextToIso('١٤٠٥-٨-١٢'), '2026-11-03')
    assert.equal(jalaliTextToIso(' 1405.8.12 '), '2026-11-03')
})

test('round trips are exact for every day of two years', () => {
    for (let t = Date.UTC(2025, 0, 1); t < Date.UTC(2027, 0, 1); t += 86400000) {
        const iso = new Date(t).toISOString().slice(0, 10)
        const { jy, jm, jd } = toJalaliParts(iso)
        assert.equal(jalaliTextToIso(`${jy}/${jm}/${jd}`), iso, iso)
    }
})

test('impossible and malformed dates are refused, not guessed', () => {
    assert.equal(jalaliTextToIso('1405/13/01'), null)
    assert.equal(jalaliTextToIso('1405/07/31'), null) // Mehr has 30 days
    assert.equal(jalaliTextToIso('1404/12/30'), null) // 1404 is not a leap year
    assert.equal(jalaliTextToIso('1403/12/30'), '2025-03-20') // 1403 is
    assert.equal(jalaliTextToIso('hello'), null)
    assert.equal(jalaliTextToIso(''), null)
    assert.equal(jalaliTextToIso(null), null)
    assert.equal(toJalaliParts('not a date'), null)
    assert.equal(toJalaliParts(null), null)
})

test('display: Jalali with Persian digits for fa and ps, plain ISO for en', () => {
    assert.equal(formatDate('2026-11-03', 'fa'), '۱۴۰۵/۰۸/۱۲')
    assert.equal(formatDate('2026-11-03', 'ps'), '۱۴۰۵/۰۸/۱۲')
    assert.equal(formatDate('2026-11-03', 'en'), '2026-11-03')
    assert.equal(formatDate('', 'fa'), '')
    assert.equal(formatDate(null, 'fa'), '')
    assert.equal(formatDate('garbage', 'fa'), 'garbage')
    assert.equal(usesJalali('fa'), true)
    assert.equal(usesJalali('en'), false)
})

test('display of a timestamp keeps the time', () => {
    assert.equal(formatDateTime('2026-11-03 06:08:02', 'fa'), '۱۴۰۵/۰۸/۱۲ ۰۶:۰۸')
    assert.equal(formatDateTime('2026-11-03T06:08:02.000000Z', 'en'), '2026-11-03 06:08')
    assert.equal(formatDateTime('2026-11-03', 'fa'), '۱۴۰۵/۰۸/۱۲')
    assert.equal(formatDateTime(null, 'fa'), '')
})
