import { KeyRound, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { Passkey } from '@/types/auth';

type PasskeyItemProps = {
    passkey: Passkey;
    onDelete: (id: number, onError: () => void) => void;
};

export default function PasskeyItem({ passkey, onDelete }: PasskeyItemProps) {
    const [isDeleting, setIsDeleting] = useState(false);
    const { t } = useTranslation();

    const handleDelete = () => {
        setIsDeleting(true);
        onDelete(passkey.id, () => setIsDeleting(false));
    };

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4 last:border-b-0">
            <div className="flex min-w-0 flex-1 items-center gap-4">
                <div className="bg-muted flex h-10 w-10 shrink-0 items-center justify-center rounded-xl">
                    <KeyRound className="text-muted-foreground h-5 w-5" />
                </div>
                <div className="min-w-0 space-y-1">
                    <div className="flex min-w-0 flex-wrap items-center gap-2.5">
                        <p className="truncate font-medium tracking-tight">
                            {passkey.name}
                        </p>
                        {passkey.authenticator && (
                            <Badge
                                variant="secondary"
                                className="text-muted-foreground tracking-wide uppercase"
                            >
                                {passkey.authenticator}
                            </Badge>
                        )}
                    </div>
                    <p className="text-muted-foreground text-sm">
                        {t('Added :when', {
                            when: passkey.created_at_diff,
                        })}
                        {passkey.last_used_at_diff && (
                            <>
                                <span className="text-muted-foreground/50 mx-1">
                                    /
                                </span>
                                {t('Last used :when', {
                                    when: passkey.last_used_at_diff,
                                })}
                            </>
                        )}
                    </p>
                </div>
            </div>

            <AlertDialogTrigger>
                <Button
                    variant="ghost"
                    size="sm"
                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                >
                    <Trash2 className="h-4 w-4" />
                    <span className="sr-only">{t('Remove')}</span>
                </Button>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {t('Remove passkey')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {t(
                                'Are you sure you want to remove the “:name” passkey? You will no longer be able to use it to sign in.',
                                { name: passkey.name },
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel variant="secondary">
                            {t('Cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            onPress={handleDelete}
                            isDisabled={isDeleting}
                        >
                            {isDeleting ? t('Removing…') : t('Remove passkey')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialogTrigger>
        </div>
    );
}
