import { User } from '@react-aria/test-utils';
import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vite-plus/test';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Popover, PopoverTrigger } from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import SegmentedControl from '@/components/primitives/segmented-control';

const testUtilUser = new User({
    interactionType: 'mouse',
    // Real timers — advanceTimer is only needed for long-press.
    advanceTimer: async (ms) => {
        await new Promise((resolve) => setTimeout(resolve, ms));
    },
});

describe('React Aria UI patterns', () => {
    it('Select honors static SelectValue children and selects by textValue', async () => {
        const onSelectionChange = vi.fn();

        render(
            <Select
                aria-label="Client"
                selectedKey={undefined}
                onSelectionChange={onSelectionChange}
            >
                <SelectTrigger>
                    <SelectValue>Custom label</SelectValue>
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        <SelectItem id="a" textValue="Alpha Co">
                            <span>Alpha Co</span>
                            <span>alpha@example.com</span>
                        </SelectItem>
                        <SelectItem id="b" textValue="Beta LLC">
                            <span>Beta LLC</span>
                            <span>beta@example.com</span>
                        </SelectItem>
                    </SelectGroup>
                </SelectContent>
            </Select>,
        );

        // Static children must render (wrapper used to discard them).
        expect(screen.getByText('Custom label')).toBeInTheDocument();

        const select = testUtilUser.createTester('Select', {
            root: screen.getByRole('button', { name: /Client|Custom label/i }),
        });

        await select.open();
        await select.toggleOptionSelection({ option: 'Beta LLC' });

        expect(onSelectionChange).toHaveBeenCalled();
        const selectedKey = onSelectionChange.mock.calls.at(-1)?.[0];
        expect(String(selectedKey)).toBe('b');
    });

    it('Popover opens from PopoverTrigger-as-root composition', async () => {
        render(
            <PopoverTrigger>
                <Button aria-label="Open inbox">Inbox</Button>
                <Popover>
                    <p>Notification list</p>
                </Popover>
            </PopoverTrigger>,
        );

        const dialog = testUtilUser.createTester('Dialog', {
            root: screen.getByRole('button', { name: 'Open inbox' }),
            overlayType: 'popover',
        });

        expect(dialog.getDialog()).toBeNull();
        await dialog.open();
        expect(dialog.getDialog()).toBeTruthy();
        expect(
            within(dialog.getDialog()!).getByText('Notification list'),
        ).toBeInTheDocument();
    });

    it('Menu opens and fires onAction for an item', async () => {
        const onAction = vi.fn();

        render(
            <DropdownMenuTrigger>
                <Button aria-label="Account menu">Account</Button>
                <DropdownMenu>
                    <DropdownMenuItem onAction={onAction}>
                        Log out
                    </DropdownMenuItem>
                </DropdownMenu>
            </DropdownMenuTrigger>,
        );

        const menu = testUtilUser.createTester('Menu', {
            root: screen.getByRole('button', { name: 'Account menu' }),
        });

        await menu.open();
        await menu.toggleOptionSelection({ option: 'Log out' });

        expect(onAction).toHaveBeenCalled();
    });

    it('SegmentedControl marks the selected segment with data-selected', () => {
        const onValueChange = vi.fn();

        render(
            <SegmentedControl
                aria-label="Appearance"
                value="dark"
                onValueChange={onValueChange}
                options={[
                    { value: 'light', label: 'Light' },
                    { value: 'dark', label: 'Dark' },
                ]}
            />,
        );

        const dark = screen.getByRole('radio', { name: 'Dark' });
        const light = screen.getByRole('radio', { name: 'Light' });

        expect(dark).toHaveAttribute('data-selected', 'true');
        expect(light).not.toHaveAttribute('data-selected', 'true');
    });
});
