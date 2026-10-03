<?php

namespace App\Ui;

use App\Errors\ApiError;
use App\Errors\DatabaseErrors;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The server-rendered UI's shared machinery.
 *
 * UI controllers call the same services as the JSON API, so the rules and
 * the error messages are the API's. Forms post plain HTML fields, which are
 * turned into the API's request-body shape (a Body) before the service sees
 * them. A failed action re-renders its page with the server's message next
 * to the action (domain §13 G5); a successful one redirects.
 */
final class Ui
{
    /**
     * Run a service call in one transaction: [result, null] on success,
     * [null, message] on refusal, with nothing written.
     */
    public static function attempt(Closure $fn): array
    {
        try {
            return [DB::transaction($fn), null];
        } catch (ApiError $e) {
            return [null, $e->getMessage()];
        } catch (QueryException $e) {
            $mapped = DatabaseErrors::map($e) ?? throw $e;

            return [null, $mapped->getMessage()];
        }
    }

    public static function user(Request $request): User
    {
        return $request->attributes->get('user');
    }

    /** GET shows the form; POST saves through the service or shows its refusal. */
    public static function crudForm(
        Request $request, string $title, array $fields, array $initial, Closure $save, Closure $done, string $back,
        bool $editing = false, array $extra = [],
    ) {
        $form = new Form($fields, $initial, $editing);
        $error = null;
        if ($request->isMethod('post')) {
            [$result, $error] = self::attempt(fn () => $save($form->body($request)));
            if ($error === null) {
                return redirect($done($result));
            }
            $form->keep($request);
        }

        return view('form', ['title' => $title, 'form' => $form->rows(), 'error' => $error, 'back' => $back] + $extra);
    }

    /**
     * @param  list<Column>  $columns
     * @param  ?Closure  $link  row => URL of its edit form or detail screen
     */
    public static function listPage(string $title, array $rows, array $columns, ?Closure $link, ?array $new = null,
        string $empty = 'Nothing here yet.')
    {
        $rows = array_map(fn ($r) => [
            'cells' => array_map(fn (Column $c) => [$c, $c->value($r)], $columns),
            'link' => $link ? $link($r) : null,
        ], $rows);

        return view('list', compact('title', 'columns', 'rows', 'new', 'empty'));
    }

    /** A same-site path to return to after sign-in, or the home page. */
    public static function safeNext(?string $next): string
    {
        return is_string($next) && str_starts_with($next, '/') && ! str_starts_with($next, '//') && ! str_contains($next, '\\')
            ? $next : '/';
    }

    /** Whether the form's CSRF token belongs to the request's session. */
    public static function csrfToken(Request $request): string
    {
        $session = (string) $request->cookies->get(\App\Services\Sessions::COOKIE, '');

        return hash_hmac('sha256', 'csrf', $session);
    }
}
