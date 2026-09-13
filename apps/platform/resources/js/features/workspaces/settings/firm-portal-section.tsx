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

type FirmPortalSectionProps = {
    portal: App.Tenancy.Data.Settings.TenantPortalSettingsData;
    errors: Record<string, string>;
};

export default function FirmPortalSection({
    portal,
    errors,
}: FirmPortalSectionProps) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Client portal sessions')}</CardTitle>
                <CardDescription>
                    {t(
                        'How long a client stays signed in after opening their link. Shorter is safer; too short and a client filling in a long questionnaire is signed out mid-answer.',
                    )}
                </CardDescription>
            </CardHeader>

            <CardContent>
                <FieldGroup className="gap-6">
                    <InheritedNumberField
                        name="portal_session_idle_minutes"
                        label={t('Sign out after inactivity')}
                        description={t(
                            'Minutes without activity before a client is signed out. The clock restarts every time they do something.',
                        )}
                        unit={t('minutes')}
                        setting={portal.session_idle_minutes}
                        error={errors.portal_session_idle_minutes}
                    />

                    <InheritedNumberField
                        name="portal_session_absolute_minutes"
                        label={t('Maximum session length')}
                        description={t(
                            'Minutes a client session can last, however active they are. They can open their link again afterwards.',
                        )}
                        unit={t('minutes')}
                        setting={portal.session_absolute_minutes}
                        error={errors.portal_session_absolute_minutes}
                    />
                </FieldGroup>
            </CardContent>
        </Card>
    );
}
