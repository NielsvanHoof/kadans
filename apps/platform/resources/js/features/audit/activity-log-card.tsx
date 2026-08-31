import { Link } from '@inertiajs/react';
import EmptyState from '@/components/primitives/empty-state';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { ActivityLogEntry } from '@/features/audit/types';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useFormatters } from '@/hooks/use-formatters';
import { useTranslation } from '@/hooks/use-translation';
import { show as showDossier } from '@/routes/workspaces/dossiers';
import type { SimplePaginated } from '@/types/pagination';

type Translate = (
    key: string,
    replacements?: Record<string, string | number>,
) => string;

/**
 * Structured list of recent tenant audit activity.
 */
export default function ActivityLogCard({
    activities,
}: {
    activities: SimplePaginated<ActivityLogEntry>;
}) {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const { formatDateTime } = useFormatters();
    const rows = activities.data;

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Recent activity')}</CardTitle>
                {/* Page-local copy: a filtered-set total would COUNT(*) activity_log. */}
                <CardDescription>
                    {rows.length === 0
                        ? t('No audit events yet')
                        : rows.length === 1
                          ? t('Latest 1 event')
                          : t('Latest :count events', {
                                count: rows.length,
                            })}
                </CardDescription>
            </CardHeader>
            <CardContent>
                {rows.length === 0 ? (
                    <EmptyState
                        title={t(
                            'Audit events will appear here as your team works in this workspace.',
                        )}
                    />
                ) : (
                    <ul className="divide-y rounded-md border">
                        {rows.map((activity) => (
                            <li
                                key={activity.id}
                                className="flex flex-col gap-2 px-4 py-3"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant="secondary">
                                        {activity.label}
                                    </Badge>
                                    {activity.created_at && (
                                        <span className="text-muted-foreground text-xs">
                                            {formatDateTime(
                                                activity.created_at,
                                            )}
                                        </span>
                                    )}
                                </div>

                                {activity.description ? (
                                    <p className="text-sm">
                                        {activity.description}
                                    </p>
                                ) : null}

                                <p className="text-muted-foreground text-sm">
                                    {activity.causer_name ?? t('System')}
                                    {activity.subject && (
                                        <>
                                            {' · '}
                                            {activity.subject.type ===
                                            'Dossier' ? (
                                                <Link
                                                    href={showDossier({
                                                        tenant: currentWorkspace,
                                                        dossier:
                                                            activity.subject.id,
                                                    })}
                                                    className="underline-offset-4 hover:underline"
                                                >
                                                    {activity.subject.name ??
                                                        t('Dossier #:id', {
                                                            id: activity.subject
                                                                .id,
                                                        })}
                                                </Link>
                                            ) : (
                                                <span>
                                                    {activity.subject.name ??
                                                        t(':type #:id', {
                                                            type: activity
                                                                .subject.type,
                                                            id: activity.subject
                                                                .id,
                                                        })}
                                                </span>
                                            )}
                                        </>
                                    )}
                                </p>

                                {activity.attribute_changes ? (
                                    <AttributeChangesList
                                        changes={activity.attribute_changes}
                                        t={t}
                                    />
                                ) : null}

                                {(activity.properties.ip ||
                                    activity.properties.route) && (
                                    <p className="text-muted-foreground text-xs">
                                        {[
                                            activity.properties.ip,
                                            activity.properties.route,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function AttributeChangesList({
    changes,
    t,
}: {
    changes: NonNullable<ActivityLogEntry['attribute_changes']>;
    t: Translate;
}) {
    const keys = [
        ...new Set([
            ...Object.keys(changes.attributes),
            ...Object.keys(changes.old),
        ]),
    ];

    if (keys.length === 0) {
        return null;
    }

    return (
        <dl className="text-muted-foreground space-y-1 text-xs">
            {keys.map((key) => {
                const oldValue = formatChangeValue(changes.old[key]);
                const newValue = formatChangeValue(changes.attributes[key]);

                return (
                    <div key={key} className="flex flex-wrap gap-1">
                        <dt className="text-foreground font-medium">{key}:</dt>
                        <dd>
                            {oldValue !== null ? (
                                <>
                                    <span className="line-through opacity-70">
                                        {oldValue}
                                    </span>
                                    {' → '}
                                </>
                            ) : null}
                            <span>{newValue ?? t('—')}</span>
                        </dd>
                    </div>
                );
            })}
        </dl>
    );
}

function formatChangeValue(value: unknown): string | null {
    if (value === null || value === undefined) {
        return null;
    }

    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }

    if (
        typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'bigint'
    ) {
        return String(value);
    }

    return JSON.stringify(value);
}
