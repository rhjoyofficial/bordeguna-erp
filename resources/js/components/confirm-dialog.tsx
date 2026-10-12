import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useState, type ReactNode } from 'react';

type ConfirmDialogProps = {
    /** The element that opens the dialog (rendered via asChild). */
    trigger: ReactNode;
    title: string;
    description?: string;
    confirmLabel?: string;
    cancelLabel?: string;
    destructive?: boolean;
    /** Show an optional free-text note captured and passed to onConfirm. */
    withNote?: boolean;
    /**
     * ⚠ Make that note mandatory: the confirm button stays disabled until
     * something is typed. Sending a claim back for correction with no words
     * tells the employee only that somebody said no, which leaves them exactly
     * as stuck as a rejection did.
     */
    noteRequired?: boolean;
    noteLabel?: string;
    notePlaceholder?: string;
    onConfirm: (note: string) => void;
};

// Shared confirmation dialog replacing window.confirm/window.prompt: a real,
// theme-consistent modal with an optional note field (used for approval reject
// reasons). Manages its own open state so it can drop into any table row.
export function ConfirmDialog({
    trigger,
    title,
    description,
    confirmLabel = 'Confirm',
    cancelLabel = 'Cancel',
    destructive = false,
    withNote = false,
    noteRequired = false,
    noteLabel = 'Note',
    notePlaceholder,
    onConfirm,
}: ConfirmDialogProps) {
    const [open, setOpen] = useState(false);
    const [note, setNote] = useState('');

    const handleConfirm = () => {
        onConfirm(note);
        setOpen(false);
        setNote('');
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && <DialogDescription>{description}</DialogDescription>}
                </DialogHeader>
                {withNote && (
                    <div className="grid gap-2">
                        <Label htmlFor="confirm-dialog-note" required={noteRequired}>
                            {noteLabel}
                        </Label>
                        <textarea
                            id="confirm-dialog-note"
                            aria-required={noteRequired || undefined}
                            value={note}
                            onChange={(event) => setNote(event.target.value)}
                            placeholder={notePlaceholder}
                            className="border-input bg-background/80 focus-visible:ring-ring min-h-24 rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                        />
                    </div>
                )}
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="outline">{cancelLabel}</Button>
                    </DialogClose>
                    <Button variant={destructive ? 'destructive' : 'default'} disabled={noteRequired && note.trim() === ''} onClick={handleConfirm}>
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
