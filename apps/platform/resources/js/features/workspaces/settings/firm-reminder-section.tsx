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

type FirmReminderSectionProps = {
    reminders: App.Tenancy.Data.Settings.TenantReminderSettingsData;
    errors: Record<string, string>;
};

export default function FirmReminderSection({
    reminders,
    errors,
}: FirmReminderSectionProps) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Client reminders')}</CardTitle>
                <CardDescription>
                    {t(
                        'The rhythm new dossiers start with. Any dossier can still be given its own interval, or none at all.',
                    )}
                </CardDescription>
            </CardHeader>

            <CardContent>
                <FieldGroup className="gap-6">
                    <InheritedNumberField
                        name="default_reminder_interval_days"
                        label={t('Default reminder interval')}
                        description={t(
                            'Days between reminders on a new dossier while documents are still outstanding.',
                        )}
                        unit={t('days')}
                        setting={reminders.default_interval_days}
                        error={errors.default_reminder_interval_days}
                    />
                </FieldGroup>
            </CardContent>
        </Card>
    );
}
