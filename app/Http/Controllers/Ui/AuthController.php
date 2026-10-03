<?php

namespace App\Http\Controllers\Ui;

use App\Services\Sessions;
use App\Services\Users;
use App\Ui\Ui;
use Illuminate\Http\Request;

/** Sign-in and sign-out (domain §13 G1, G2). */
class AuthController
{
    public function login(Request $request)
    {
        $next = Ui::safeNext($request->input('next'));
        if ($request->isMethod('get') && Sessions::fromRequest($request) !== null) {
            return redirect($next);
        }
        $error = null;
        $email = (string) $request->input('email', '');
        if ($request->isMethod('post')) {
            $password = (string) $request->input('password', '');
            $user = trim($email) !== '' && $password !== '' ? Users::authenticate($email, $password) : null;
            if ($user !== null) {
                return redirect($next)->withCookie(Sessions::cookie($request, Sessions::create($user)));
            }
            $error = 'Invalid email or password.';
        }

        return view('login', ['title' => 'Sign in', 'error' => $error, 'email' => $email, 'next' => $request->input('next', '')]);
    }

    public function logout(Request $request)
    {
        Sessions::revoke($request);

        return redirect('/login')->withCookie(Sessions::clearingCookie($request));
    }
}
