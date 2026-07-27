<?php

declare(strict_types=1);

namespace App\Console\Commands\Tenancy;

use App\Actions\Accounts\CreateNewUser;
use App\Actions\Tenancy\ProvisionTenantAction;
use App\Enums\Role;
use Database\Seeders\RolesAndPermissionsSeeder;
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

/**
 * Engineering bootstrap: create a verified owner and their first workspace.
 * After this, workspace owners invite colleagues via Members.
 */
final class CreateOwnerCommand extends Command
{
    protected $signature = 'tenancy:create-owner
                            {name? : Full name of the owner}
                            {email? : Email address used to sign in}
                            {--firm= : Firm / workspace name}
                            {--password= : Plain-text password (random if omitted)}
                            {--role= : Role of the user (default: owner)}';

    protected $description = 'Create a verified workspace owner and provision their firm';

    public function handle(
        CreateNewUser $createNewUser,
        ProvisionTenantAction $provisionTenant,
    ): int {
        $name = $this->resolveName();
        $email = $this->resolveEmail();
        $role = $this->resolveRole();

        if ($role === null) {
            return self::FAILURE;
        }

        $firmName = $this->resolveFirm($name);
        $password = $this->resolvePassword();

        try {
            Validator::make(
                [
                    'name' => $name,
                    'email' => $email,
                    'firm' => $firmName,
                    'password' => $password,
                    'password_confirmation' => $password,
                    'role' => $role->value,
                ],
                [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                    'firm' => ['required', 'string', 'max:255'],
                    'password' => ['required', 'string', Password::defaults(), 'confirmed'],
                    'role' => ['required', 'string', Rule::enum(Role::class)],
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

        // Global permissions must exist before tenant roles are seeded.
        $this->callSilent('db:seed', [
            '--class' => RolesAndPermissionsSeeder::class,
            '--force' => true,
        ]);

        $user = $createNewUser->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

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

    private function resolveFirm(string $name): string
    {
        $firmOption = $this->option('firm');

        if (is_string($firmOption) && $firmOption !== '') {
            return mb_trim($firmOption);
        }

        return text(
            label: 'Firm',
            default: "{$name}'s firm",
            required: true,
            hint: 'Optional — press enter to use the default.',
            validate: ['firm' => 'required|string|max:255'],
            transform: fn (string $value): string => mb_trim($value),
        );
    }

    private function resolvePassword(): string
    {
        $passwordOption = $this->option('password');

        if (is_string($passwordOption) && $passwordOption !== '') {
            return $passwordOption;
        }

        $password = password(
            label: 'Password',
            hint: 'Leave empty to generate a random password.',
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
        );

        return $password !== '' ? $password : Str::password(20);
    }
}
