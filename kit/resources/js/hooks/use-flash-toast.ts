import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            // Typed by App\Data\FlashData (InertiaConfig['flashDataType']).
            const data = event.detail.flash.toast;

            if (!data) {
                return;
            }

            toast[data.type](data.message);
        });
    }, []);
}
