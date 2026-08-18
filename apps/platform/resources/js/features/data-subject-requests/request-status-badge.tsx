import { Badge } from '@/components/ui/badge';
import { useTranslation } from '@/hooks/use-translation';
import type { DataSubjectRequest } from './types';

/**
 * Overdue is shown instead of the plain status, not alongside it: a request past
 * its one-month deadline is the only thing about it worth reading first.
 */
export default function RequestStatusBadge({
    request,
}: {
    request: DataSubjectRequest;
}) {
    const { t } = useTranslation();

    if (request.is_overdue) {
        return <Badge variant="destructive">{t('Overdue')}</Badge>;
    }

    return (
        <Badge variant={request.status === 'pending' ? 'secondary' : 'outline'}>
            {request.status_label}
        </Badge>
    );
}
