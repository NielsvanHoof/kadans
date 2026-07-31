<?php

declare(strict_types=1);

namespace App\Tenancy\Console\Commands;

use App\Accounts\Actions\CreateNewUser;
use App\Tenancy\Actions\ProvisionTenantAction;
use App\Tenancy\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

use function is_string;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

#[Signature('tenancy:create-owner {name? : Full name of the owner} {email? : Email address used to sign in} {--firm= : Firm / workspace name (omit for default onboarding)} {--password= : Plain-text password (random if omitted)} {--role= : Role of the user when a firm is provisioned (default: owner)}')]
#[Description('Create a verified owner, optionally provisioning their firm')]
final class CreateOwnerCommand extends Command
{
    public function handle(
        CreateNewUser $createNewUser,
        ProvisionTenantAction $provisionTenant,
    ): int {
        $name = $this->resolveName();
        $email = $this->resolveEmail();
        $firmName = $this->resolveFirm();
        $role = null;

        if ($firmName !== null) {
            $role = $this->resolveRole();

            if (! $role instanceof Role) {
                return self::FAILURE;
            }
        }

        $password = $this->resolvePassword();

        try {
            Validator::make(
                [
                    'name' => $name,
                    'email' => $email,
                    'firm' => $firmName,
                    'password' => $password,
                    'password_confirmation' => $password,
                    'role' => $role?->value,
                ],
                [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                    'firm' => ['nullable', 'string', 'max:255'],
                    'password' => ['required', 'string', Password::defaults(), 'confirmed'],
                    'role' => ['required_with:firm', 'nullable', 'string', Rule::enum(Role::class)],
                ],
            )->validate();
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    error($message);
                }
            }

            return self::FAILURE;
        }

        $user = $createNewUser->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        if ($firmName === null) {
            info('Owner created. They will complete onboarding on first login.');
            table(
                headers: ['Field', 'Value'],
                rows: [
                    ['Name', $user->name],
                    ['Email', $user->email],
                    ['Password', $password],
                    ['Firm', '(none — onboarding)'],
                ],
            );

            return self::SUCCESS;
        }

        $this->callSilent('db:seed', [
            '--class' => RolesAndPermissionsSeeder::class,
            '--force' => true,
        ]);

        $tenant = $provisionTenant->handle(
            name: $firmName,
            owner: $user,
            ownerRole: $role,
        );

        info('Owner and workspace created.');
        table(
            headers: ['Field', 'Value'],
            rows: [
                ['Name', $user->name],
                ['Email', $user->email],
                ['Password', $password],
                ['Firm', $tenant->name],
                ['Workspace slug', $tenant->slug],
                ['Role', $role->value],
            ],
        );

        return self::SUCCESS;
    }

    private function resolveName(): string
    {
        $nameArgument = $this->argument('name');

        if (is_string($nameArgument) && $nameArgument !== '') {
            return mb_trim($nameArgument);
        }

        return text(
            label: 'Name',
            placeholder: 'E.g. Ada Lovelace',
            required: true,
            validate: ['name' => 'required|string|max:255'],
            transform: fn (string $value): string => mb_trim($value),
        );
    }

    private function resolveEmail(): string
    {
        $emailArgument = $this->argument('email');

        if (is_string($emailArgument) && $emailArgument !== '') {
            return mb_strtolower(mb_trim($emailArgument));
        }

        return mb_strtolower(text(
            label: 'Email',
            placeholder: 'E.g. ada@example.com',
            required: true,
            validate: ['email' => 'required|string|email|max:255|unique:users,email'],
            transform: fn (string $value): string => mb_trim($value),
        ));
    }

    private function resolveRole(): ?Role
    {
        $roleOption = $this->option('role');

        if (is_string($roleOption) && $roleOption !== '') {
            $role = Role::tryFrom($roleOption);

            if ($role === null) {
                error("Invalid role: $roleOption. Available: ".implode(', ', Role::values()));

                return null;
            }

            return $role;
        }

        $selected = select(
            label: 'Role',
            options: collect(Role::cases())
                ->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])
                ->all(),
            default: Role::Owner->value,
            hint: 'Owner is the default for a new workspace.',
        );

        return Role::from($selected);
    }

    /**
     * Resolve an optional firm name.
     *
     * When omitted (or left blank interactively), no workspace is provisioned
     * and the user goes through the default onboarding experience on login.
     */
    private function resolveFirm(): ?string
    {
        $firmOption = $this->option('firm');

        if (is_string($firmOption)) {
            $firmOption = mb_trim($firmOption);

            return $firmOption !== '' ? $firmOption : null;
        }

        // Option omitted with name provided via args: treat as no firm (scripted usage).
        $nameArgument = $this->argument('name');

        if (is_string($nameArgument) && $nameArgument !== '') {
            return null;
        }

        // Fully interactive run: allow leaving firm blank to skip provisioning.
        $firm = text(
            label: 'Firm',
            default: '',
            required: false,
            validate: ['firm' => 'nullable|string|max:255'],
            hint: 'Leave empty to skip — user will go through onboarding.',
            transform: fn (string $value): string => mb_trim($value),
        );

        return $firm !== '' ? $firm : null;
    }

    private function resolvePassword(): string
    {
        $passwordOption = $this->option('password');

        if (is_string($passwordOption) && $passwordOption !== '') {
            return $passwordOption;
        }

        $password = password(
            label: 'Password',
            validate: function (string $value): ?string {
                if ($value === '') {
                    return null;
                }

                $validator = Validator::make(
                    ['password' => $value],
                    ['password' => Password::defaults()],
                );

                return $validator->fails()
                    ? $validator->errors()->first('password')
                    : null;
            },
            hint: 'Leave empty to generate a random password.',
        );

        return $password !== '' ? $password : Str::password(20);
    }
}
