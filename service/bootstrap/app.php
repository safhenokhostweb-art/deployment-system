<?php

use App\Http\Middleware\SetDashboardLocale;
use App\Http\Middleware\VerifyAgentRequestSignature;
use App\Support\DashboardMessageTranslator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'agent.signed' => VerifyAgentRequestSignature::class,
        ]);
        // Presentation-only: sets the dashboard's display locale to Persian
        // and translates the fixed set of hardcoded Service/Controller
        // error strings. Never runs on the 'api' group, so agent/webhook
        // JSON responses are byte-for-byte unaffected.
        $middleware->web(append: [SetDashboardLocale::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Translates the small, enumerable set of abort()/abort_unless()
        // guard messages (409/422, always with a literal message) that
        // Services/Controllers throw directly rather than via
        // $request->validate(). Status code is preserved exactly, so
        // existing assertStatus(409/422) tests are unaffected — only the
        // rendered body's text changes, and only for non-API requests.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            $message = $e->getMessage();
            if ($message === '' || !in_array($e->getStatusCode(), [409, 422], true)) {
                return null;
            }

            return response()->view('errors.dashboard', [
                'statusCode' => $e->getStatusCode(),
                'message' => DashboardMessageTranslator::translateError($message),
            ], $e->getStatusCode());
        });

        // Every intentional error an agent route returns is already one of
        // two types Laravel already renders safely on its own: an
        // HttpExceptionInterface (401 from VerifyAgentRequestSignature::
        // reject(), 403/404/409/422 from an abort()/abort_if() call) or a
        // ValidationException (422 from $request->validate(), e.g.
        // AgentJobController::heartbeat()'s 'agent_version' rule) — both
        // excluded below, untouched, so no existing status code or body
        // shape changes for either. This callback only ever fires for a
        // genuinely UNCAUGHT exception on an agent route (a real bug, not
        // an intentional rejection) — without it, shouldRenderJsonWhen()
        // above still returns JSON, but Laravel's default body/status
        // depend on APP_DEBUG, which could leak an exception message, file
        // path, or stack trace to the wire. This guarantees a fixed, safe
        // body regardless of that setting. The real exception is still
        // logged via Laravel's normal report() path — this only changes
        // what's rendered back, never what's recorded.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->is('api/agent/*') || $e instanceof HttpExceptionInterface || $e instanceof ValidationException) {
                return null;
            }

            return response()->json([
                'error' => 'agent_request_failed',
                'message' => 'The Deployment Service could not process this request. See the Hub application log for details.',
            ], 500);
        });
    })->create();
