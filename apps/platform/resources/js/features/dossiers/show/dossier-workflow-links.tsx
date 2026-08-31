import { Link } from '@inertiajs/react';
import { Check, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    dossierStageCompletion,
    dossierStageHint,
    dossierStageLabel,
    STAGE_ORDER,
} from '@/features/dossiers/stage/dossier-stage';
import type { Dossier } from '@/features/dossiers/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import {
    builder as builderDossier,
    review as reviewDossier,
} from '@/routes/workspaces/dossiers';

/**
 * Compact Build Form → Send Invite → Review strip.
 *
 * Build Form and Review open dedicated routes; Send Invite selects the Access
 * panel on this page, so it is a button rather than a link.
 *
 * Only the current stage carries its hint. Hints on every stage made this
 * bulky enough to need its own card — and a hint on a finished stage tells
 * the reader nothing they can act on.
 */
export default function DossierWorkflowLinks({
    dossier,
    onSelectInvite,
}: {
    dossier: Dossier;
    onSelectInvite: () => void;
}) {
    const { t } = useTranslation();
    const currentWorkspace = useCurrentWorkspace();
    const completion = dossierStageCompletion(dossier);
    const hasFormItems = dossier.document_requests.length > 0;
    const routeArgs = {
        tenant: currentWorkspace,
        dossier: dossier.id,
    };

    const currentStage =
        STAGE_ORDER.find((stage) => completion[stage] === 'incomplete') ?? null;

    return (
        <nav aria-label={t('Dossier workflow progress')} className="min-w-0">
            <ol className="flex min-w-0 flex-wrap items-center gap-0.5">
                {STAGE_ORDER.map((stage, index) => {
                    const done = completion[stage] === 'completed';
                    const current = stage === currentStage;
                    // Review needs a form; the Waiting panel explains the empty case.
                    const disabled = stage === 'review' && !hasFormItems;

                    const body = (
                        <StageBody
                            index={index}
                            label={dossierStageLabel(stage, t)}
                            done={done}
                            current={current}
                        />
                    );

                    const className = cn(
                        'focus-visible:ring-ring/50 flex min-w-0 items-center gap-1.5 rounded-md px-2 py-1.5 text-left transition-[color,background-color] focus-visible:ring-[3px] focus-visible:outline-none',
                        disabled
                            ? 'cursor-not-allowed opacity-60'
                            : 'hover:bg-muted/40',
                        current && 'bg-muted/50 ring-primary/40 ring-1',
                    );

                    return (
                        <li key={stage} className="flex min-w-0 items-center">
                            {disabled ? (
                                <div
                                    className={className}
                                    title={t(
                                        'Add at least one questionnaire item first.',
                                    )}
                                >
                                    {body}
                                </div>
                            ) : stage === 'invite' ? (
                                <button
                                    type="button"
                                    onClick={onSelectInvite}
                                    className={className}
                                    aria-current={current ? 'step' : undefined}
                                >
                                    {body}
                                </button>
                            ) : (
                                <Link
                                    href={
                                        stage === 'prepare'
                                            ? builderDossier(routeArgs).url
                                            : reviewDossier(routeArgs).url
                                    }
                                    className={className}
                                    aria-current={current ? 'step' : undefined}
                                >
                                    {body}
                                </Link>
                            )}
                            {index < STAGE_ORDER.length - 1 ? (
                                <ChevronRight
                                    className="text-muted-foreground/50 mx-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                            ) : null}
                        </li>
                    );
                })}
            </ol>

            {currentStage !== null ? (
                <p className="text-muted-foreground mt-1.5 text-xs text-pretty">
                    {dossierStageHint(currentStage, t)}
                </p>
            ) : null}
        </nav>
    );
}

function StageBody({
    index,
    label,
    done,
    current,
}: {
    index: number;
    label: string;
    done: boolean;
    current: boolean;
}): ReactNode {
    return (
        <>
            <span
                className={cn(
                    'font-data flex size-5 shrink-0 items-center justify-center rounded-full border text-[0.6875rem] tabular-nums',
                    done
                        ? 'border-success-border bg-success-muted text-success-foreground'
                        : current
                          ? 'border-primary text-primary'
                          : 'border-border text-muted-foreground',
                )}
            >
                {done ? (
                    <Check className="size-3" aria-hidden="true" />
                ) : (
                    index + 1
                )}
            </span>
            <span
                className={cn(
                    'min-w-0 truncate text-sm',
                    current
                        ? 'font-semibold'
                        : done
                          ? 'font-medium'
                          : 'text-muted-foreground font-medium',
                )}
            >
                {label}
            </span>
        </>
    );
}
