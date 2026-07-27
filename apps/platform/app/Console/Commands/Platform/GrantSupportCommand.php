<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Console\Command;

use function is_string;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

/**
 * Grant the global platform support role to an existing user.
 */
final class GrantSupportCommand extends Command
{
    protected $signature = 'platform:grant-support
                            {email? : Email of an existing user}';

    protected $description = 'Grant the platform support role (cross-firm read-only sessions)';

    public function handle(): int
    {
        $emailArgument = $this->argument('email');
        $email = mb_strtolower(mb_trim(
            (is_string($emailArgument) && $emailArgument !== '')
                ? $emailArgument
                : text(
                    label: 'Email',
                    placeholder: 'E.g. eng@qompose.com',
                    required: true,
                    validate: ['email' => 'required|email'],
                    transform: fn (string $value): string => mb_trim($value),
                ),
        ));

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            error("No user found with email [{$email}].");

            return self::FAILURE;
        }

        $user->forceFill(['platform_role' => PlatformRole::Support])->save();

        info('Platform support role granted.');
        table(
            headers: ['Field', 'Value'],
            rows: [
                ['Name', $user->name],
                ['Email', $user->email],
                ['Platform role', PlatformRole::Support->value],
            ],
        );

        return self::SUCCESS;
    }
}
