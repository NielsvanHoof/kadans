import { Eye, EyeOff } from 'lucide-react';
import type { ComponentProps, Ref } from 'react';
import { useState } from 'react';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
} from '@/components/ui/input-group';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

export default function PasswordInput({
    className,
    ref,
    ...props
}: Omit<ComponentProps<'input'>, 'type'> & { ref?: Ref<HTMLInputElement> }) {
    const [showPassword, setShowPassword] = useState(false);
    const { t } = useTranslation();

    return (
        <InputGroup className={cn(className, 'flex')}>
            <InputGroupInput
                type={showPassword ? 'text' : 'password'}
                ref={ref}
                {...props}
            />
            <InputGroupAddon align="inline-end">
                <InputGroupButton
                    size="icon-xs"
                    onClick={() => setShowPassword((prev) => !prev)}
                    aria-label={
                        showPassword ? t('Hide password') : t('Show password')
                    }
                    aria-pressed={showPassword}
                >
                    {showPassword ? (
                        <EyeOff aria-hidden="true" />
                    ) : (
                        <Eye aria-hidden="true" />
                    )}
                </InputGroupButton>
            </InputGroupAddon>
        </InputGroup>
    );
}
