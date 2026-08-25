import { Link } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
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
 * Top row of the dossier state band: Build Form → Send Invite → Review.
 *
 * Build Form and Review open dedicated routes; Send Invite selects the Access
 * panel on this page, so it is a button rather than a link.
 *
 * Only the current stage carries its hint. Hints on every stage are what made
 * this rail bulky enough to need its own card — and a hint on a finished stage
 * tells the reader nothing they can act on.
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
        <nav aria-label={t('Dossier workflow progress')}>
            <ol className="grid sm:grid-cols-3">
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
                            showArrow={!disabled && stage !== 'invite'}
                        />
                    );

                    const className = cn(
                        'flex h-full min-w-0 items-center gap-2 border-t border-ledger-rule px-4 py-3 text-left transition-colors first:border-t-0 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none sm:border-t-0 sm:border-l sm:first:border-l-0',
                        disabled
                            ? 'cursor-not-allowed opacity-60'
                            : 'hover:bg-muted/40',
                    );

                    return (
                        <li key={stage} className="min-w-0">
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
                                    className={cn(className, 'w-full')}
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
                        </li>
                    );
                })}
            </ol>

            {/*
             * One hint, for the stage you are on, on its own line. Inside the
             * cells it made a single column taller than its siblings and the row
             * stopped reading as a sequence.
             */}
            {currentStage !== null ? (
                <p className="border-t border-ledger-rule px-4 py-2 text-xs text-pretty text-muted-foreground">
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
    showArrow,
}: {
    index: number;
    label: string;
    done: boolean;
    current: boolean;
    showArrow: boolean;
}): ReactNode {
    return (
        <>
            <span
                className={cn(
                    'flex size-5 shrink-0 items-center justify-center rounded-full border font-data text-[0.6875rem] tabular-nums',
                    done
                        ? 'border-success-border bg-success-muted text-success-foreground'
                        : current
                          ? 'border-foreground/70 text-foreground'
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
                          : 'font-medium text-muted-foreground',
                )}
            >
                {label}
            </span>
            {showArrow ? (
                <ArrowRight
                    className="size-3.5 shrink-0 text-muted-foreground/60"
                    aria-hidden="true"
                />
            ) : null}
        </>
    );
}
