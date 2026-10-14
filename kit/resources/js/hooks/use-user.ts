import { usePage } from '@inertiajs/react';
import type { UserData } from '@/generated';

/**
 * The signed-in user, for pages behind the `auth` middleware. The shared `auth.user` is `UserData | null`, because
 * guests see pages too; on a guest page, read `usePage().props.auth.user` and handle `null` instead.
 */
export function useUser(): UserData {
    const { user } = usePage().props.auth;

    if (user === null) {
        throw new Error(
            'useUser() was called on a page that guests can see. Read usePage().props.auth.user there instead.',
        );
    }

    return user;
}
