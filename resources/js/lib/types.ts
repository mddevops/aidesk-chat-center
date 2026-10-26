import type { PageProps as InertiaPageProps } from '@inertiajs/core';
export interface SharedProps extends InertiaPageProps {
    auth: { user: {id: number; name: string; email: string; role: string} | null; permissions: string[] };
    flash: {success?: string};
}
