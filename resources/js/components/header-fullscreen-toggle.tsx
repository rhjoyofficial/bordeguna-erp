import { Button } from '@/components/ui/button';
import { Maximize, Minimize } from 'lucide-react';
import { useEffect, useState } from 'react';

export default function HeaderFullscreenToggle() {
    const [isFullscreen, setIsFullscreen] = useState(false);

    useEffect(() => {
        const onChange = () => setIsFullscreen(Boolean(document.fullscreenElement));
        document.addEventListener('fullscreenchange', onChange);
        return () => document.removeEventListener('fullscreenchange', onChange);
    }, []);

    const toggle = () => {
        if (document.fullscreenElement) {
            void document.exitFullscreen();
        } else {
            void document.documentElement.requestFullscreen().catch(() => {
                // Some browsers refuse outside a user gesture chain; nothing to do.
            });
        }
    };

    return (
        <Button variant="ghost" size="icon" onClick={toggle} aria-label={isFullscreen ? 'Exit full screen' : 'Enter full screen'}>
            {isFullscreen ? <Minimize className="h-4.5 w-4.5" /> : <Maximize className="h-4.5 w-4.5" />}
        </Button>
    );
}
