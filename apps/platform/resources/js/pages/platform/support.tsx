import { Form, Head, router, setLayoutProps } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/primitives/heading';
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
import { store, index as supportIndex } from '@/routes/platform/support';

type SupportTenant = {
    id: number;
    name: string;
    slug: string;
};

/**
 * Internal firm picker for platform support — enter any firm read-only.
 * Search uses Spatie Query Builder filter[q].
 */
export default function PlatformSupport({
    tenants,
    filters = {},
}: {
    tenants: SupportTenant[];
    filters?: { q?: string };
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState(filters.q ?? '');
    const [reason, setReason] = useState('');

    setLayoutProps({
        breadcrumbs: [
            {
                title: t('Platform support'),
                href: supportIndex(),
            },
        ],
    });

    return (
        <>
            <Head title={t('Platform support')} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4 md:p-8">
                <Heading
                    level={1}
                    title={t('Platform support')}
                    description={t(
                        'Enter a firm in read-only mode to investigate issues. Access is time-boxed and audited.',
                    )}
                />

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get(
                            supportIndex.url({
                                query: {
                                    filter: search ? { q: search } : undefined,
                                },
                            }),
                            {},
                            { preserveState: true },
                        );
                    }}
                >
                    <FieldGroup className="gap-2 sm:flex-row sm:items-end">
                        <Field className="flex-1">
                            <FieldLabel htmlFor="search">
                                {t('Search firms')}
                            </FieldLabel>
                            <Input
                                id="search"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder={t('Name or slug')}
                            />
                        </Field>
                        <Field orientation="horizontal" className="sm:w-auto">
                            <Button type="submit" variant="outline">
                                {t('Search')}
                            </Button>
                        </Field>
                    </FieldGroup>
                </form>

                <Field>
                    <FieldLabel htmlFor="reason">{t('Reason')}</FieldLabel>
                    <Input
                        id="reason"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder={t('E.g. Investigating ticket #123')}
                        required
                    />
                    <FieldDescription>
                        {t('Required for audit. Describe why you need access.')}
                    </FieldDescription>
                </Field>

                <ul className="divide-y rounded-lg border">
                    {tenants.length === 0 ? (
                        <li className="text-muted-foreground px-4 py-6 text-sm">
                            {t('No firms found.')}
                        </li>
                    ) : (
                        tenants.map((tenant) => (
                            <li
                                key={tenant.id}
                                className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                            >
                                <div>
                                    <p className="font-medium">{tenant.name}</p>
                                    <p className="text-muted-foreground text-sm">
                                        {tenant.slug}
                                    </p>
                                </div>

                                <Form
                                    {...store.form(tenant)}
                                    options={{ preserveScroll: true }}
                                    className="flex items-center gap-2"
                                >
                                    {({ processing, errors }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="reason"
                                                value={reason}
                                            />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                isDisabled={
                                                    processing ||
                                                    reason.trim().length < 3
                                                }
                                            >
                                                {t('Enter firm')}
                                            </Button>
                                            <InputError
                                                message={errors.reason}
                                            />
                                        </>
                                    )}
                                </Form>
                            </li>
                        ))
                    )}
                </ul>
            </div>
        </>
    );
}
