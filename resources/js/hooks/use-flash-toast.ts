import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

// Fire a toast whenever the server flashes success/error. Inertia keeps the
// same flash object identity across a partial reload of unrelated props, so we
// track the last message we showed to avoid re-toasting on every render.
export function useFlashToast() {
    const flash = usePage<SharedData>().props.flash;
    const lastShown = useRef<string | null>(null);

    useEffect(() => {
        const message = flash?.success ?? flash?.error;
        if (!message) {
            lastShown.current = null;
            return;
        }

        const key = `${flash.success ? 'success' : 'error'}:${message}`;
        if (key === lastShown.current) {
            return;
        }
        lastShown.current = key;

        if (flash.success) {
            toast.success(flash.success);
        } else if (flash.error) {
            toast.error(flash.error);
        }
    }, [flash]);
}
