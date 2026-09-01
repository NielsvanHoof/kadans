import { Head, setLayoutProps } from '@inertiajs/react';
import UploadedDocumentController from '@/actions/App/Dossiers/Http/Controllers/UploadedDocumentController';
import UploadedDocumentShowContent from '@/features/document-requests/staff/review/uploaded-document-show-content';
import type { UploadedDocumentShowProps } from '@/features/document-requests/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import {
    index as dossierIndex,
    show as showDossier,
} from '@/routes/workspaces/dossiers';

export default function ShowUploadedDocument(props: UploadedDocumentShowProps) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const { uploaded_document: uploadedDocument, dossier } = props;

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Dossiers'),
                href: dossierIndex(currentWorkspace),
            },
            ...(dossier
                ? [
                      {
                          title: dossier.title,
                          href: showDossier({
                              tenant: currentWorkspace,
                              dossier: dossier.id,
                          }),
                      },
                  ]
                : []),
            {
                title: uploadedDocument.original_filename,
                href: UploadedDocumentController.show.url({
                    tenant: currentWorkspace,
                    uploadedDocument: uploadedDocument.id,
                }),
            },
        ],
    });

    return (
        <>
            <Head
                title={`${t('Extraction')} · ${uploadedDocument.original_filename}`}
            />
            <UploadedDocumentShowContent {...props} />
        </>
    );
}
