<?php

namespace App\Http\Middleware;

use App\Errors\DatabaseErrors;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Each API request runs in one transaction, committed only if it succeeds.
 * The schema's deferred constraint triggers fire at commit, so a refusal
 * there is translated like any other database error.
 */
class Transaction
{
    public function handle(Request $request, Closure $next): Response
    {
        DB::beginTransaction();
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if ($response->getStatusCode() >= 400) {
            DB::rollBack();

            return $response;
        }
        try {
            DB::commit();
        } catch (QueryException $e) {
            DB::rollBack();
            $error = DatabaseErrors::map($e) ?? throw $e;

            return response()->json(['error' => $error->getMessage()], $error->status);
        }

        return $response;
    }
}
