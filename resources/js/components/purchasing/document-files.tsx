import { useTranslation } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export type DocumentFile = { id: number; name: string; uploaded_by: string | null; url: string };

/**
 * ⭐ Initial plan B1 (R58, 25 Oct 2026): "supplier memo photo, courier receipt,
 * labour slip" on a goods receipt or a supplier bill, the way the purchase
 * order already keeps its files. `baseUrl` is the document's files route
 * (`/purchasing/receipts/12/files`). A file never changes the document's
 * figures; removing one is logged.
 */
export function DocumentFiles({ files, baseUrl, canAttach }: { files: DocumentFile[]; baseUrl: string; canAttach: boolean }) {
    const { t } = useTranslation();
    const [uploading, setUploading] = useState(false);

    const upload = (file: File | undefined) => {
        if (!file) return;
        setUploading(true);
        router.post(baseUrl, { file }, { forceFormData: true, preserveScroll: true, onFinish: () => setUploading(false) });
    };

    return (
        <section className="bg-card rounded-xl border p-4">
            <p className="text-[15px] font-semibold">{t('ipp.files.title')}</p>
            {files.length === 0 ? (
                <p className="text-muted-foreground mt-1 text-xs">{t('ipp.files.none')}</p>
            ) : (
                <ul className="mt-2 grid gap-1 text-[13px]">
                    {files.map((file) => (
                        <li key={file.id} className="flex items-center gap-2">
                            <a href={file.url} target="_blank" rel="noreferrer" className="underline">
                                {file.name}
                            </a>
                            {file.uploaded_by && <span className="text-muted-foreground text-xs">{file.uploaded_by}</span>}
                            {canAttach && (
                                <button
                                    type="button"
                                    className="text-muted-foreground hover:text-destructive text-xs"
                                    onClick={() => router.delete(`${baseUrl}/${file.id}`, { preserveScroll: true })}
                                >
                                    {t('ipp.files.remove')}
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {canAttach && (
                <label className="text-muted-foreground hover:text-foreground mt-2 block w-fit cursor-pointer text-xs underline">
                    {uploading ? t('ipp.files.uploading') : t('ipp.files.attach')}
                    <input
                        type="file"
                        className="hidden"
                        accept=".pdf,.jpg,.jpeg,.png,.webp,.csv,.xls,.xlsx,.doc,.docx"
                        onChange={(e) => upload(e.target.files?.[0])}
                    />
                </label>
            )}
        </section>
    );
}
