<script setup>
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import StatusBadge from '../../Components/StatusBadge.vue'
import SubscriptionPanel from '../../Components/SubscriptionPanel.vue'
import InstancePanel from '../../Components/InstancePanel.vue'
import InvoicesPanel from '../../Components/InvoicesPanel.vue'
import ContactsPanel from '../../Components/ContactsPanel.vue'
import MessagesPanel from '../../Components/MessagesPanel.vue'
import { useAuth } from '../../composables/useAuth'
import { t } from '../../i18n'

const props = defineProps({
    customer: Object,
    subscription: { type: Object, default: null },
    instance: { type: Object, default: null },
    contacts: { type: Array, default: () => [] },
    messages: { type: Array, default: () => [] },
    packages: { type: Array, default: () => [] },
    erpModules: { type: Array, default: () => [] },
    invoices: { type: Array, default: () => [] },
    changes: { type: Array, default: () => [] },
    usage: { type: Object, default: null },
    issuedToken: { type: String, default: null },
})

const { can } = useAuth()
const name = props.customer.name_fa || props.customer.name_en

const rows = [
    ['customers.name_en', props.customer.name_en],
    ['customers.name_fa', props.customer.name_fa],
    ['customers.name_ps', props.customer.name_ps],
    ['customers.business_type', props.customer.business_type],
    ['customers.province', props.customer.province],
    ['customers.city', props.customer.city],
    ['customers.license_no', props.customer.license_no],
    ['customers.base_currency', props.customer.base_currency],
]
</script>

<template>
    <Head :title="name" />
    <AppLayout>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <Link href="/customers" class="text-sm text-violet-700 hover:underline">← {{ t('customers.title') }}</Link>
            <h1 class="text-xl font-bold">{{ name }}</h1>
            <StatusBadge kind="status" :value="customer.status" />
            <Link v-if="can('customers.manage')" :href="`/customers/${customer.id}/edit`" class="ms-auto rounded-md border border-slate-300 px-3 py-1.5 text-sm">{{ t('common.edit') }}</Link>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-lg border border-slate-200 bg-white p-4">
                <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                    <template v-for="[label, value] in rows" :key="label">
                        <dt class="text-slate-500">{{ t(label) }}</dt>
                        <dd>{{ value || t('common.none') }}</dd>
                    </template>
                </dl>
            </section>

            <SubscriptionPanel
                :customer-id="customer.id"
                :subscription="subscription"
                :packages="packages"
                :erp-modules="erpModules"
                :changes="changes"
                :usage="usage"
            />

            <InstancePanel :customer-id="customer.id" :instance="instance" :issued-token="issuedToken" />

            <ContactsPanel :customer-id="customer.id" :contacts="contacts" />

            <MessagesPanel :customer-id="customer.id" :has-subscription="!!subscription" :messages="messages" />

            <div class="lg:col-span-2">
                <InvoicesPanel :customer-id="customer.id" :currency="customer.base_currency" :has-subscription="!!subscription" :invoices="invoices" />
            </div>
        </div>
    </AppLayout>
</template>
