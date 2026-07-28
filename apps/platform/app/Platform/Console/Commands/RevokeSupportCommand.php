<?php

declare(strict_types=1);

namespace App\Platform\Console\Commands;

use App\Accounts\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use function is_string;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

#[Signature('platform:revoke-support {email? : Email of an existing user}')]
#[Description('Revoke the platform support role from a user')]
final class RevokeSupportCommand extends Command
{
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
