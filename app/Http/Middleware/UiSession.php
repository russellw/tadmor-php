<?php

namespace App\Http\Middleware;

use App\Services\Sessions;
use App\Ui\Nav;
use App\Ui\Ui;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * The UI's pages need a live session (domain §13 G1): without one, the
 * login screen, remembering where the user was going. With one, every view
 * gets the user and the navigation, and every POST must carry the form
 * token derived from the session, so another site cannot submit our forms.
 */
class UiSession
{
    public function handle(Request $request, Closure $next)
    {
        $user = Sessions::fromRequest($request);
        if ($user === null) {
            return redirect('/login?'.http_build_query(['next' => $request->getRequestUri()]));
        }
        if ($request->isMethod('post') && ! hash_equals(Ui::csrfToken($request), (string) $request->input('_token'))) {
            return response()->view('message', ['title' => 'Form expired',
                'message' => 'The form was out of date. Go back, reload the page, and try again.'], 419);
        }
        $request->attributes->set('user', $user);
        View::share('user', $user);
        View::share('nav', Nav::for($user, $request->getPathInfo()));
        View::share('csrf', Ui::csrfToken($request));

        return $next($request);
    }
}
