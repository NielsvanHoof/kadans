import { usePasskeyRegister } from '@laravel/passkeys/react';
import { useState } from 'react';
import InputError from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    onSuccess: () => void;
};

export default function PasskeyRegistration({ onSuccess }: Props) {
    const { t } = useTranslation();
    const [name, setName] = useState(() => {
        const ua = navigator.userAgent;

        const browser = [
            { pattern: /Edg|Edge/, name: 'Edge' },
            { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
            { pattern: /Firefox|FxiOS/, name: 'Firefox' },
            { pattern: /Chrome|CriOS/, name: 'Chrome' },
            { pattern: /Safari/, name: 'Safari' },
        ].find(({ pattern }) => pattern.test(ua))?.name;

        const os = [
            { pattern: /iPhone/, name: 'iPhone' },
            { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
            { pattern: /Android/, name: 'Android' },
            { pattern: /Mac/, name: 'Mac' },
            { pattern: /Windows/, name: 'Windows' },
        ].find(({ pattern }) => pattern.test(ua))?.name;

        // Proper nouns stay as-is; only the connector is localized.
        if (browser && os) {
            return `${browser} on ${os}`;
        }

        return browser || os || '';
    });

    const [showForm, setShowForm] = useState(false);
    const { register, isLoading, error, isSupported } = usePasskeyRegister({
        onSuccess: () => {
            setName('');
            setShowForm(false);
            onSuccess();
        },
    });

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!name.trim()) {
            return;
        }

        await register(name);
    };

    const handleCancel = () => {
        setShowForm(false);
        setName('');
    };

    if (!isSupported) {
        return (
            <div className="text-sm text-muted-foreground">
                {t('Passkeys are not supported in this browser.')}
            </div>
        );
    }

    if (!showForm) {
        return (
            <Button variant="outline" onClick={() => setShowForm(true)}>
                {t('Add passkey')}
            </Button>
        );
    }

    return (
        <form
            onSubmit={handleSubmit}
            className="rounded-lg border border-border bg-muted/50 p-4"
        >
            <FieldGroup className="gap-4">
                <Field data-invalid={Boolean(error)}>
                    <FieldLabel htmlFor="passkey-name">
                        {t('Passkey name')}
                    </FieldLabel>
                    <Input
                        id="passkey-name"
                        type="text"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder={t('e.g., MacBook Pro, iPhone')}
                        autoFocus
                        aria-invalid={Boolean(error)}
                    />
                    <FieldDescription className="text-xs">
                        {t('A name helps you identify this passkey later.')}
                    </FieldDescription>
                    <InputError message={error ?? undefined} />
                </Field>

                <Field orientation="horizontal" className="gap-2">
                    <Button type="submit" disabled={isLoading || !name.trim()}>
                        {isLoading ? t('Registering…') : t('Register passkey')}
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={handleCancel}
                    >
                        {t('Cancel')}
                    </Button>
                </Field>
            </FieldGroup>
        </form>
    );
}
