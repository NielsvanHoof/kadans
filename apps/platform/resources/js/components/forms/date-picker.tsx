import { type CalendarDate, parseDate } from '@internationalized/date';
import { CalendarIcon, X } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverTrigger } from '@/components/ui/popover';
import { useTranslation } from '@/hooks/use-translation';
import { formatDate } from '@/lib/format-date-time';
import { cn } from '@/lib/utils';

type DatePickerProps = {
    id: string;
    name: string;
    defaultValue?: string;
    invalid?: boolean;
    required?: boolean;
    triggerClassName?: string;
};

/**
 * Date field backed by React Aria's Calendar.
 *
 * The wire format is a plain `YYYY-MM-DD` string, which is exactly what
 * `CalendarDate` parses and serialises — so unlike the previous `Date`-based
 * version there is no hand-rolled parsing, and no timezone can shift the day
 * across a boundary. Month and weekday names follow the locale supplied by
 * `I18nProvider` in `app.tsx`.
 */
export default function DatePicker({
    id,
    name,
    defaultValue = '',
    invalid = false,
    required = false,
    triggerClassName,
}: DatePickerProps) {
    const { locale, t } = useTranslation();
    const [isOpen, setIsOpen] = useState(false);
    const [selectedDate, setSelectedDate] = useState<CalendarDate | null>(() =>
        parseDateValue(defaultValue),
    );

    return (
        <div className="flex gap-2">
            <input
                type="hidden"
                name={name}
                value={selectedDate ? selectedDate.toString() : ''}
                readOnly
            />

            <PopoverTrigger isOpen={isOpen} onOpenChange={setIsOpen}>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    aria-invalid={invalid}
                    aria-required={required}
                    className={cn(
                        'min-w-0 flex-1 justify-start text-left font-normal',
                        !selectedDate && 'text-muted-foreground',
                        triggerClassName,
                    )}
                >
                    <CalendarIcon aria-hidden="true" />
                    <span className="truncate">
                        {selectedDate
                            ? formatDate(selectedDate.toString(), locale, {
                                  dateStyle: 'long',
                              })
                            : t('Choose a date')}
                    </span>
                </Button>
                <Popover className="w-auto p-0">
                    <Calendar
                        value={selectedDate}
                        onChange={(date) => {
                            setSelectedDate(date);
                            setIsOpen(false);
                        }}
                        autoFocus
                    />
                </Popover>
            </PopoverTrigger>

            {selectedDate ? (
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    onPress={() => setSelectedDate(null)}
                >
                    <X aria-hidden="true" />
                    <span className="sr-only">{t('Clear date')}</span>
                </Button>
            ) : null}
        </div>
    );
}

/** `parseDate` throws on anything that is not a valid `YYYY-MM-DD`. */
function parseDateValue(value: string): CalendarDate | null {
    if (!value) {
        return null;
    }

    try {
        return parseDate(value);
    } catch {
        return null;
    }
}
