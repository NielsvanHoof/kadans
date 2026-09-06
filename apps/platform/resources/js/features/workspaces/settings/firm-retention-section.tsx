import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { FieldGroup } from '@/components/ui/field';
import InheritedNumberField from '@/features/workspaces/settings/inherited-number-field';
import { useTranslation } from '@/hooks/use-translation';

type FirmRetentionSectionProps = {
    retention: App.Tenancy.Data.TenantRetentionSettingsData;
    errors: Record<string, string>;
};

export default function FirmRetentionSection({
    retention,
    errors,
}: FirmRetentionSectionProps) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('How long records are kept')}</CardTitle>
                <CardDescription>
                    {t(
                        'Archived records are deleted for good once this period passes. Check the period your firm has agreed with its clients and what you are required to keep.',
                    )}
                </CardDescription>
            </CardHeader>

            <CardContent>
                <FieldGroup className="gap-6">
                    <InheritedNumberField
                        name="retention_archived_days"
                        label={t('Archived clients and dossiers')}
                        description={t(
                            'Days an archived client or dossier is kept, including its documents, before it is permanently deleted.',
                        )}
                        unit={t('days')}
                        setting={retention.archived_days}
                        error={errors.retention_archived_days}
                    />

                    <InheritedNumberField
                        name="retention_activity_log_days"
                        label={t('Activity history')}
                        description={t(
                            'Days of recorded activity kept before older entries are removed.',
                        )}
                        unit={t('days')}
                        setting={retention.activity_log_days}
                        error={errors.retention_activity_log_days}
                    />
                </FieldGroup>
            </CardContent>
        </Card>
    );
}
