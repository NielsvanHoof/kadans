<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Models\User;
use Illuminate\Console\Command;

use function is_string;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

/**
 * Revoke the global platform support role from a user.
 */
final class RevokeSupportCommand extends Command
{
    protected $signature = 'platform:revoke-support
                            {email? : Email of an existing user}';

    protected $description = 'Revoke the platform support role from a user';

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

        $user->forceFill(['platform_role' => null])->save();

        info('Platform support role revoked.');
        table(
            headers: ['Field', 'Value'],
            rows: [
                ['Name', $user->name],
                ['Email', $user->email],
                ['Platform role', '—'],
            ],
        );

        return self::SUCCESS;
    }
}
