<script setup>
import { computed } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { t } from '../i18n'

defineProps({ title: String })

const page = usePage()
const user = computed(() => page.props.auth.user)
const can = (ability) => user.value?.abilities?.includes(ability)

// Only the screens that exist and that this role may open.
const nav = computed(() => [
    { key: 'dashboard', href: '/dashboard', ability: 'reports.view' },
    { key: 'customers', href: '/customers', ability: 'customers.view' },
    { key: 'packages', href: '/packages', ability: 'packages.view' },
    { key: 'staff', href: '/staff', ability: 'staff.manage' },
    { key: 'audit', href: '/audit', ability: 'audit.view' },
].filter((item) => can(item.ability)))

const active = (href) => page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`)
const logout = () => router.post('/logout')
</script>

<template>
    <div class="flex min-h-screen">
        <aside class="flex w-56 shrink-0 flex-col bg-slate-900 text-slate-200">
            <div class="px-5 py-5 text-lg font-bold text-white">NextBook <span class="text-violet-400">Control</span></div>
            <nav class="flex-1 space-y-1 px-3">
                <Link
                    v-for="item in nav"
                    :key="item.key"
                    :href="item.href"
                    class="block rounded-md px-3 py-2 text-sm transition hover:bg-slate-800"
                    :class="active(item.href) ? 'bg-violet-600 text-white hover:bg-violet-600' : ''"
                >
                    {{ t(`nav.${item.key}`) }}
                </Link>
            </nav>
            <div class="border-t border-slate-800 px-5 py-4 text-sm">
                <div class="font-semibold text-white">{{ user?.name }}</div>
                <div class="mb-2 text-xs text-slate-400">{{ user?.role }}</div>
                <button class="text-xs text-violet-300 hover:text-white" @click="logout">{{ t('nav.logout') }}</button>
            </div>
        </aside>

        <main class="min-w-0 flex-1 p-6">
            <div v-if="$page.props.flash.success" class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">
                {{ $page.props.flash.success }}
            </div>
            <div v-if="$page.props.flash.error" class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-800">
                {{ $page.props.flash.error }}
            </div>
            <h1 v-if="title" class="mb-5 text-xl font-bold">{{ title }}</h1>
            <slot />
        </main>
    </div>
</template>
