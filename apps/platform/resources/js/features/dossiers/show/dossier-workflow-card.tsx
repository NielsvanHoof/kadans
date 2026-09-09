import { Form } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import DossierCompletionController from '@/actions/App/Dossiers/Http/Controllers/Lifecycle/DossierCompletionController';
import InputError from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Field, FieldGroup } from '@/components/ui/field';
import { useDossierCapabilities } from '@/features/dossiers/permissions/dossier-permissions-context';
import type { DossierWorkflowProgress } from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { inlineActionOptions } from '@/lib/inline-action-options';

export default function DossierWorkflowCard({
    dossier,
}: {
    dossier: DossierWorkflowProgress;
}) {
    const { t } = useTranslation();
    const { canReview } = useDossierCapabilities();
    const summary = dossier.review_summary;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Review progress')}</CardTitle>
                <CardDescription>
                    {t(
                        'Review submitted items and complete the dossier when everything is approved.',
                    )}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid grid-cols-2 gap-3 text-sm">
                    <ReviewCount label={t('Waiting')} value={summary.pending} />
                    <ReviewCount
                        label={t('Ready to review')}
                        value={summary.submitted}
                    />
                    <ReviewCount
                        label={t('Changes requested')}
                        value={summary.rejected}
                    />
                    <ReviewCount
                        label={t('Approved')}
                        value={summary.accepted}
                    />
                </div>

                {dossier.status === 'completed' ? (
                    <div className="border-success-border bg-success-muted text-success-foreground flex items-center gap-2 rounded-md border px-3 py-2 text-sm font-medium">
                        <CheckCircle2 className="size-4" aria-hidden="true" />
                        {t('Dossier completed')}
                    </div>
                ) : canReview ? (
                    <div className="space-y-2">
                        <CompleteDossierDialog
                            dossierId={dossier.id}
                            ready={dossier.ready_to_complete}
                        />
                        {!dossier.ready_to_complete && (
                            <p className="text-muted-foreground text-xs">
                                {t('Every item must be approved first.')}
                            </p>
                        )}
                    </div>
                ) : (
                    <p className="text-muted-foreground text-xs">
                        {t(
                            'A reviewer will complete the dossier after all items have been approved.',
                        )}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function CompleteDossierDialog({
    dossierId,
    ready,
}: {
    dossierId: number;
    ready: boolean;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();

    return (
        <DialogTrigger>
            <Button type="button" className="w-full" isDisabled={!ready}>
                <CheckCircle2 aria-hidden="true" />
                {t('Complete dossier')}
            </Button>
            <Dialog>
                <DialogHeader>
                    <DialogTitle>{t('Complete this dossier?')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'This marks the dossier as finished. The client will no longer be able to submit changes.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...DossierCompletionController.store.form({
                        tenant: currentWorkspace,
                        dossier: dossierId,
                    })}
                    options={inlineActionOptions}
                >
                    {({ errors, processing }) => (
                        <FieldGroup className="gap-4">
                            <Field data-invalid={Boolean(errors.dossier)}>
                                <InputError message={errors.dossier} />
                            </Field>
                            <Field>
                                <DialogFooter className="gap-2">
                                    <DialogClose
                                        type="button"
                                        variant="secondary"
                                    >
                                        {t('Cancel')}
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        isDisabled={processing}
                                    >
                                        {t('Complete dossier')}
                                    </Button>
                                </DialogFooter>
                            </Field>
                        </FieldGroup>
                    )}
                </Form>
            </Dialog>
        </DialogTrigger>
    );
}

function ReviewCount({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-md border px-3 py-2">
            <p className="text-xl font-semibold tabular-nums">{value}</p>
            <p className="text-muted-foreground text-xs">{label}</p>
        </div>
    );
}
