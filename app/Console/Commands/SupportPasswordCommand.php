<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Runs INSIDE a client space (called by the master): sets the password of the
 * support login. The password comes through the environment, not the command
 * line, so it never shows in the host's process list.
 */
class SupportPasswordCommand extends Command
{
    protected $signature = 'castlit:support-password {email : The support login, e.g. admin@shop.com}';

    protected $description = 'Set the support login password from CASTLIT_SUPPORT_PASSWORD (called by the master).';

    public function handle(): int
    {
        $password = (string) getenv('CASTLIT_SUPPORT_PASSWORD');
        if (strlen($password) < 12) {
            $this->line(json_encode(['status' => 'error', 'message' => 'Password missing or too short.']));

            return self::FAILURE;
        }

        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->line(json_encode(['status' => 'absent']));

            return self::SUCCESS;
        }

        // The model's "hashed" cast hashes it.
        $user->forceFill(['password' => $password])->save();
        // Signed-in sessions of that login end too: the old password is gone.
        $user->tokens()->delete();
        $this->line(json_encode(['status' => 'success']));

        return self::SUCCESS;
    }
}
