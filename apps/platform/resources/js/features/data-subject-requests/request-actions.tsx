import { Form } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';
import ConfirmDestroyDialog from '@/components/feedback/confirm-destroy-dialog';
import InputError from '@/components/primitives/input-error';
import { Button, LinkButton } from '@/components/ui/button';
import { Field, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import {
    download as downloadRoute,
    fulfil as fulfilRoute,
    refuse as refuseRoute,
} from '@/routes/workspaces/data-subject-requests';
import type { DataSubjectRequest } from './types';

/**
 * Fulfil / refuse / download for one request.
 *
 * Erasure is confirmed through the destructive dialog because it cannot be
 * undone; an export is only a build, so it submits directly.
 */
export default function RequestActions({
    request,
    canManage,
}: {
    request: DataSubjectRequest;
    canManage: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const [refusing, setRefusing] = useState(false);

    const routeParameters = {
        tenant: currentWorkspace,
        dataSubjectRequest: request.id,
    };

    if (request.can_download) {
        return (
            <LinkButton
                variant="outline"
                size="sm"
                href={downloadRoute(routeParameters).url}
            >
                <Download className="size-4" />
                {t('Download')}
            </LinkButton>
        );
    }

    if (!canManage || request.status !== 'pending') {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            {request.type === 'erasure' ? (
                <ConfirmDestroyDialog
                    title={t('Erase this client’s identity?')}
                    description={t(
                        'The name, email, portal access and activity trail are removed. Dossiers and documents are kept, because the firm is required to retain them. This cannot be undone.',
                    )}
                    confirmLabel={t('Erase identity')}
                    form={{
                        ...fulfilRoute.form(routeParameters),
                    }}
                    trigger={
                        <Button variant="destructive" size="sm">
                            {t('Fulfil')}
                        </Button>
                    }
                />
            ) : (
                <Form {...fulfilRoute.form(routeParameters)}>
                    {({ processing }) => (
                        <Button type="submit" size="sm" isDisabled={processing}>
                            {t('Build export')}
                        </Button>
                    )}
                </Form>
            )}

            {refusing ? (
                <Form
                    {...refuseRoute.form(routeParameters)}
                    className="flex w-full flex-col gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field
                                data-invalid={Boolean(errors.refusal_reason)}
                            >
                                <FieldLabel htmlFor={`refusal-${request.id}`}>
                                    {t('Why is this request refused?')}
                                </FieldLabel>
                                <Textarea
                                    id={`refusal-${request.id}`}
                                    name="refusal_reason"
                                    rows={2}
                                    required
                                    placeholder={t(
                                        'For example: the dossiers fall inside the statutory retention period.',
                                    )}
                                />
                                <InputError message={errors.refusal_reason} />
                            </Field>
                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                    isDisabled={processing}
                                >
                                    {t('Record refusal')}
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setRefusing(false)}
                                >
                                    {t('Cancel')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            ) : (
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => setRefusing(true)}
                >
                    {t('Refuse')}
                </Button>
            )}
        </div>
    );
}
