<?php

namespace App\Services;

use App\Errors\ApiError;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Login users (spec/api.md §3 and §5.1, spec/domain.md §12). */
class Users
{
    /** Email addresses are trimmed; case is ignored by the citext column. */
    public static function normalizeEmail(string $email): string
    {
        return trim($email);
    }

    public static function validatePassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new ApiError(422, 'password must be at least 8 characters');
        }
    }

    /**
     * Create the user, or if the email exists, reset their name, password,
     * and administrator flag, reactivate them, and revoke their sessions.
     * This is the out-of-band bootstrap (`artisan tadmor:adduser`).
     */
    public static function addOrReset(string $email, string $fullName, string $password, bool $isAdmin): User
    {
        $email = self::normalizeEmail($email);
        $fullName = trim($fullName);
        if (! str_contains($email, '@')) {
            throw new ApiError(422, 'email must contain @');
        }
        if ($fullName === '') {
            throw new ApiError(422, 'full name is required');
        }
        self::validatePassword($password);

        return DB::transaction(function () use ($email, $fullName, $password, $isAdmin) {
            $user = User::query()->where('email', $email)->first() ?? new User(['email' => $email]);
            $user->fill([
                'full_name' => $fullName,
                'password_hash' => Hash::make($password),
                'is_admin' => $isAdmin,
                'is_active' => true,
            ])->save();
            Sessions::revokeAll($user->id);

            return $user;
        });
    }

    /**
     * The active user with these credentials, or null. Unknown emails still
     * pay for one hash check, so the three failures take similar time.
     */
    public static function authenticate(string $email, string $password): ?User
    {
        static $dummy;
        $user = User::query()->where('email', self::normalizeEmail($email))->first();
        if ($user === null) {
            Hash::check($password, $dummy ??= Hash::make('not a password'));

            return null;
        }
        if (! Hash::check($password, $user->password_hash) || ! $user->is_active) {
            return null;
        }

        return $user;
    }
}
