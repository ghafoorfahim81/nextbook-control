import { usePage } from '@inertiajs/vue3'

/** What the signed-in member of staff may do, to show only the buttons that will work. */
export function useAuth() {
    const page = usePage()
    const can = (ability) => !!page.props.auth.user?.abilities?.includes(ability)
    const canAny = (...abilities) => abilities.some(can)

    return { can, canAny }
}
