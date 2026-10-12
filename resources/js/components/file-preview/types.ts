/** Which reader draws a file; decided on the server by FileManagerController::previewKind. */
export type PreviewKind = 'image' | 'pdf' | 'video' | 'audio' | 'text' | 'markdown' | 'sheet' | 'docx' | 'pptx' | 'doc' | 'ppt' | 'zip';

export interface ReaderProps {
    /** The authenticated attachments.show URL, the only way a file leaves. */
    url: string;
    downloadUrl: string;
    filename: string;
}
