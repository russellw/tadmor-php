<?php

namespace App\Services;

use App\Errors\ApiError;
use App\Http\Body;
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

    /** The UserRecord of spec/api.md §5.1. */
    public static function json(User $u): array
    {
        return [
            'id' => $u->id, 'email' => $u->email, 'full_name' => $u->full_name,
            'is_active' => $u->is_active, 'is_admin' => $u->is_admin,
        ];
    }

    public static function all(): array
    {
        return User::query()->orderBy('email')->orderBy('id')->get()->map(self::json(...))->all();
    }

    public static function get(int $id): User
    {
        return User::query()->find($id) ?? throw new ApiError(404, 'user not found');
    }

    private static function checkEmail(string $email): string
    {
        $email = self::normalizeEmail($email);
        if (! str_contains($email, '@')) {
            throw new ApiError(422, 'email must contain @');
        }

        return $email;
    }

    public static function create(Body $b): int
    {
        $email = $b->requiredStr('email');
        $fullName = $b->requiredStr('full_name');
        $password = $b->requiredStr('password');
        $email = self::checkEmail($email);
        self::validatePassword($password);
        $user = User::query()->create([
            'email' => $email, 'full_name' => $fullName, 'password_hash' => Hash::make($password),
            'is_admin' => $b->bool('is_admin'),
        ]);

        return $user->id;
    }

    public static function update(User $caller, int $id, Body $b): void
    {
        $email = $b->requiredStr('email');
        $fullName = $b->requiredStr('full_name');
        $isActive = $b->bool('is_active');
        $isAdmin = $b->bool('is_admin');
        $user = self::get($id);
        $email = self::checkEmail($email);
        if ($user->id === $caller->id && ! $isActive) {
            throw new ApiError(422, 'you cannot deactivate yourself');
        }
        if ($user->id === $caller->id && ! $isAdmin) {
            throw new ApiError(422, 'you cannot remove your own administrator role');
        }
        $user->fill(['email' => $email, 'full_name' => $fullName, 'is_active' => $isActive, 'is_admin' => $isAdmin])->save();
    }

    /** Set a user's password and revoke all of their sessions. */
    public static function setPassword(int $id, Body $b): void
    {
        $password = $b->requiredStr('password');
        $user = self::get($id);
        self::validatePassword($password);
        $user->fill(['password_hash' => Hash::make($password)])->save();
        Sessions::revokeAll($id);
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
