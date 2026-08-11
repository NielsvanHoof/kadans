import { Form, usePage } from '@inertiajs/react';
import InputError from '@/components/primitives/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Field, FieldLabel } from '@/components/ui/field';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCurrentWorkspace } from '@/hooks/use-current-workspace';
import { useTranslation } from '@/hooks/use-translation';
import { store as storeRequest } from '@/routes/workspaces/data-subject-requests';

type ClientOption = App.Shared.Data.PersonOptionData;

/**
 * Logging is separate from fulfilling: the regulation allows a month to answer,
 * and erasure is irreversible, so the request goes on record first.
 */
export default function LogRequestForm() {
    const currentWorkspace = useCurrentWorkspace();
    const { t } = useTranslation();
    const clients = (usePage().props.clients ?? []) as ClientOption[];

    if (clients.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardContent className="pt-6">
                <Form
                    {...storeRequest.form(currentWorkspace)}
                    resetOnSuccess
                    className="flex flex-wrap items-end gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <Field
                                className="min-w-56 flex-1"
                                data-invalid={Boolean(errors.client_id)}
                            >
                                <FieldLabel htmlFor="client_id">
                                    {t('Client')}
                                </FieldLabel>
                                {/* React Aria keys SelectItem by `id`, not
                                    `value`. `defaultValue` type-checks via
                                    GlobalDOMAttributes but is inert — use
                                    defaultSelectedKey or the form posts the
                                     */}
                                <Select
                                    isRequired
                                    name="client_id"
                                    placeholder={t('Client')}
                                    aria-label={t('Client')}
                                    defaultSelectedKey={String(
                                        clients[0]?.id ?? '',
                                    )}
                                >
                                    <SelectTrigger
                                        id="client_id"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            {clients.map((client) => (
                                                <SelectItem
                                                    key={client.id}
                                                    id={String(client.id)}
                                                >
                                                    {client.name}
                                                </SelectItem>
                                            ))}
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.client_id} />
                            </Field>

                            <Field
                                className="min-w-48"
                                data-invalid={Boolean(errors.type)}
                            >
                                <FieldLabel htmlFor="type">
                                    {t('Request')}
                                </FieldLabel>
                                <Select
                                    name="type"
                                    placeholder={t('Request')}
                                    aria-label={t('Request')}
                                    defaultSelectedKey="export"
                                >
                                    <SelectTrigger id="type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectGroup>
                                            <SelectItem id="export">
                                                {t('Data export')}
                                            </SelectItem>
                                            <SelectItem id="erasure">
                                                {t('Data erasure')}
                                            </SelectItem>
                                        </SelectGroup>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.type} />
                            </Field>

                            <Button type="submit" isDisabled={processing}>
                                {t('Log request')}
                            </Button>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}
