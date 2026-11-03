<script setup>
import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import Modal from './Modal.vue'
import DateField from './DateField.vue'
import { useAuth } from '../composables/useAuth'
import { useDates } from '../composables/useDates'
import { t } from '../i18n'

const { fd } = useDates()

const props = defineProps({
    customerId: String,
    currency: { type: String, default: 'AFN' },
    hasSubscription: Boolean,
    invoices: { type: Array, default: () => [] },
})

const { can } = useAuth()
const open = ref(null) // invoice | payment
const paying = ref(null)

const today = () => new Date().toISOString().slice(0, 10)
const invoice = useForm({ issued_on: today(), due_on: '', period_from: '', period_to: '', amount: '', discount: 0, currency: props.currency, description: '' })
const payment = useForm({ amount: '', paid_on: today(), method: 'cash', reference: '', note: '' })

const close = () => { open.value = null; paying.value = null }
const saveInvoice = () => invoice.post(`/customers/${props.customerId}/invoices`, { preserveScroll: true, onSuccess: () => { invoice.reset('amount', 'description'); close() } })
const openPayment = (row) => { paying.value = row; payment.amount = row.balance; open.value = 'payment' }
const savePayment = () => payment.post(`/invoices/${paying.value.id}/payments`, { preserveScroll: true, onSuccess: () => { payment.reset('reference', 'note'); close() } })

const voidInvoice = (row) => window.confirm(t('billing.void_confirm')) && router.post(`/invoices/${row.id}/void`, {}, { preserveScroll: true })
const removePayment = (p) => window.confirm(t('billing.remove_confirm')) && router.delete(`/payments/${p.id}`, { preserveScroll: true })

const tone = { paid: 'bg-emerald-100 text-emerald-800', partial: 'bg-amber-100 text-amber-800', unpaid: 'bg-red-100 text-red-800', void: 'bg-slate-200 text-slate-600' }
const input = 'w-full rounded-md border border-slate-300 px-3 py-2 text-sm'
const primary = 'rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50'
</script>

<template>
    <section class="rounded-lg border border-slate-200 bg-white p-4">
        <div class="mb-3 flex items-center">
            <h2 class="me-auto font-semibold">{{ t('billing.title') }}</h2>
            <button v-if="can('billing.manage') && hasSubscription" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50" @click="open = 'invoice'">{{ t('billing.new') }}</button>
        </div>

        <p v-if="!invoices.length" class="text-sm text-slate-500">{{ t('billing.none') }}</p>

        <div v-for="row in invoices" :key="row.id" class="mb-3 rounded-lg border border-slate-200">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-1 p-3 text-sm">
                <span class="font-medium" dir="ltr">{{ row.number }}</span>
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="tone[row.state]">{{ t(`billing.states.${row.state}`) }}</span>
                <span dir="ltr">{{ fd(row.issued_on) }}</span>
                <span v-if="row.period_from && row.period_to" class="text-slate-500" dir="ltr">{{ fd(row.period_from) }} → {{ fd(row.period_to) }}</span>
                <span>{{ t('billing.amount') }}: <b>{{ row.amount.toLocaleString() }} {{ row.currency }}</b><template v-if="row.discount"> (−{{ row.discount.toLocaleString() }})</template></span>
                <span>{{ t('billing.paid') }}: {{ row.paid.toLocaleString() }}</span>
                <span>{{ t('billing.balance') }}: {{ row.balance.toLocaleString() }}</span>
                <span v-if="can('billing.manage')" class="ms-auto flex gap-2">
                    <button v-if="row.state !== 'void' && row.balance > 0" class="text-violet-700 hover:underline" @click="openPayment(row)">{{ t('billing.record_payment') }}</button>
                    <button v-if="row.state !== 'void'" class="text-red-700 hover:underline" @click="voidInvoice(row)">{{ t('billing.void') }}</button>
                </span>
            </div>
            <p v-if="row.description" class="px-3 pb-2 text-xs text-slate-500">{{ row.description }}</p>
            <ul v-if="row.payments.length" class="border-t bg-slate-50 text-sm">
                <li v-for="p in row.payments" :key="p.id" class="flex flex-wrap items-center gap-x-5 px-3 py-1.5">
                    <span dir="ltr">{{ fd(p.paid_on) }}</span>
                    <b>{{ p.amount.toLocaleString() }}</b>
                    <span>{{ t(`billing.methods.${p.method}`) }}</span>
                    <span v-if="p.reference" class="text-slate-500" dir="ltr">{{ p.reference }}</span>
                    <button v-if="can('billing.manage')" class="ms-auto text-xs text-red-700 hover:underline" @click="removePayment(p)">{{ t('billing.remove') }}</button>
                </li>
            </ul>
        </div>

        <Modal v-if="open === 'invoice'" :title="t('billing.new')" wide @close="close">
            <form class="space-y-3" @submit.prevent="saveInvoice">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('billing.issued_on') }}</span><DateField v-model="invoice.issued_on" /><span v-if="invoice.errors.issued_on" class="text-red-600">{{ invoice.errors.issued_on }}</span></div>
                    <div class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('billing.due_on') }}</span><DateField v-model="invoice.due_on" clearable /></div>
                    <div class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('billing.period_from') }}</span><DateField v-model="invoice.period_from" clearable /></div>
                    <div class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('billing.period_to') }}</span><DateField v-model="invoice.period_to" clearable /></div>
                    <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('billing.amount') }} *</span><input v-model="invoice.amount" type="number" min="0" step="any" :class="input" dir="ltr" /><span v-if="invoice.errors.amount" class="text-red-600">{{ invoice.errors.amount }}</span></label>
                    <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('billing.discount') }}</span><input v-model="invoice.discount" type="number" min="0" step="any" :class="input" dir="ltr" /></label>
                    <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('billing.currency') }}</span><input v-model="invoice.currency" maxlength="3" :class="input" dir="ltr" /></label>
                </div>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('billing.description') }}</span><input v-model="invoice.description" :class="input" /></label>
                <button type="submit" :disabled="invoice.processing" :class="primary">{{ t('common.create') }}</button>
            </form>
        </Modal>

        <Modal v-if="open === 'payment'" :title="t('billing.record_payment')" @close="close">
            <form class="space-y-3" @submit.prevent="savePayment">
                <p class="text-sm text-slate-600" dir="ltr">{{ paying?.number }} — {{ t('billing.balance') }}: {{ paying?.balance.toLocaleString() }} {{ paying?.currency }}</p>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('billing.amount') }}</span><input v-model="payment.amount" type="number" min="0" step="any" :class="input" dir="ltr" /><span v-if="payment.errors.amount" class="text-red-600">{{ payment.errors.amount }}</span></label>
                    <div class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('billing.paid_on') }}</span><DateField v-model="payment.paid_on" />
                        <span v-if="payment.errors.paid_on" class="text-red-600">{{ payment.errors.paid_on }}</span></div>
                    <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('billing.method') }}</span>
                        <select v-model="payment.method" :class="input"><option v-for="m in ['cash', 'hawala', 'bank', 'other']" :key="m" :value="m">{{ t(`billing.methods.${m}`) }}</option></select></label>
                    <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('billing.reference') }}</span><input v-model="payment.reference" :class="input" dir="ltr" /></label>
                </div>
                <label class="block text-sm"><span class="mb-1 block text-slate-600">{{ t('billing.note') }}</span><input v-model="payment.note" :class="input" /></label>
                <button type="submit" :disabled="payment.processing" :class="primary">{{ t('billing.record_payment') }}</button>
            </form>
        </Modal>
    </section>
</template>
