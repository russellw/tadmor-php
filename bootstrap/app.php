<?php

use App\Errors\ApiError;
use App\Errors\DatabaseErrors;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\Transaction;
use App\Services\Sessions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: function () {
            // The probes and the JSON API carry no framework middleware: no
            // Laravel session, cookie encryption, or CSRF token. The API has
            // its own session (app/Services/Sessions.php).
            Route::group([], base_path('routes/probes.php'));
            Route::prefix('api')->middleware(Transaction::class)->group(base_path('routes/api.php'));
        },
        commands: __DIR__.'/../routes/console.php',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['authenticated' => Authenticate::class, 'admin' => RequireAdmin::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(ApiError::class);

        // Every error under /api/ is {"error": "..."} (spec/api.md §1.4).
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api', 'api/*')) {
                return null;
            }
            if ($e instanceof NotFoundHttpException || $e instanceof MethodNotAllowedHttpException) {
                // Authentication wraps the whole API, unknown paths included.
                $e = Sessions::fromRequest($request) === null
                    ? new ApiError(401, 'not authenticated')
                    : new ApiError(404, 'not found');
            }
            if ($e instanceof QueryException) {
                $e = DatabaseErrors::map($e) ?? $e;
            }
            if ($e instanceof ApiError) {
                return response()->json(['error' => $e->getMessage()], $e->status);
            }
            report($e);

            return response()->json(['error' => 'internal error'], 500);
        });
    })
    ->create();
