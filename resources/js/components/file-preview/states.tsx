import { Button } from '@/components/ui/button';
import { AlertTriangle, Download, Loader2 } from 'lucide-react';
import { type ReactNode } from 'react';

export function PreviewLoading({ label = 'Opening the file...' }: { label?: string }) {
    return (
        <div className="text-muted-foreground flex items-center gap-2 py-10 text-sm" role="status">
            <Loader2 className="size-4 animate-spin" />
            {label}
        </div>
    );
}

/** A preview that could not be drawn always leaves the download one tap away. */
export function PreviewMessage({ downloadUrl, icon, children }: { downloadUrl: string; icon?: ReactNode; children: ReactNode }) {
    return (
        <div className="bg-muted/30 flex flex-col items-center gap-3 rounded-md border px-4 py-10 text-center">
            <div className="text-muted-foreground">{icon ?? <AlertTriangle className="size-8" />}</div>
            <p className="text-muted-foreground max-w-md text-sm">{children}</p>
            <Button variant="outline" size="sm" asChild>
                <a href={downloadUrl}>
                    <Download className="size-4" />
                    Download
                </a>
            </Button>
        </div>
    );
}

/** A quiet line under a preview saying it is not the whole file. */
export function TruncatedNote({ children }: { children: ReactNode }) {
    return <p className="text-muted-foreground text-xs">{children}</p>;
}
