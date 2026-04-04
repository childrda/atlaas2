import { usePage } from '@inertiajs/react';
import type { User } from '@/types/models';

export function useCurrentUser(): User {
    const { auth } = usePage().props as { auth: { user: User } };
    return auth.user;
}
