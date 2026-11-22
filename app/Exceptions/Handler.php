<?php

namespace App\Exceptions;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use PDOException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Database down / no internet to Neon: never show SQL, host names or queries to users.
        // The full error still goes to storage/logs/laravel.log.
        $this->renderable(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof QueryException || $e instanceof PDOException) {
                $lost = str_contains($e->getMessage(), 'could not translate host name')
                    || str_contains($e->getMessage(), 'Connection refused')
                    || str_contains($e->getMessage(), 'timeout expired')
                    || str_contains($e->getMessage(), 'could not connect')
                    || str_contains($e->getMessage(), 'SQLSTATE[08');

                return response()->json([
                    'message' => __($lost ? 'errors.DB_UNREACHABLE' : 'errors.SERVER_ERROR'),
                    'code'    => $lost ? 'DB_UNREACHABLE' : 'SERVER_ERROR',
                ], $lost ? 503 : 500);
            }

            return null;
        });
    }
}
