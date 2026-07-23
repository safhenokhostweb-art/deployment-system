<?php

namespace App\Http\Middleware;

use App\Support\DashboardMessageTranslator;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web-only Persian locale for the dashboard. config('app.locale') stays
 * 'en' (untouched, so API responses are unaffected — this only runs on the
 * 'web' middleware group). Also rewrites the fixed set of literal English
 * strings Controllers hardcode into flash/error messages, since those
 * Controllers are out of scope to edit this phase.
 */
class SetDashboardLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale('fa');
        Carbon::setLocale('fa');

        $response = $next($request);

        // Only a redirect the controller just issued can carry freshly
        // flashed 'status'/'errors' data worth translating. Gating on this
        // (rather than session()->has(...)) avoids re-flashing — and so
        // indefinitely resurrecting — flash data left over from an earlier
        // request on every subsequent plain page view.
        if ($response instanceof RedirectResponse) {
            if (session()->has('status')) {
                session()->put('status', DashboardMessageTranslator::translateFlash((string) session('status')));
            }

            if (session()->has('errors')) {
                $bag = session('errors');
                if ($bag instanceof ViewErrorBag) {
                    foreach ($bag->getBags() as $bagName => $messageBag) {
                        $translated = new MessageBag(
                            collect($messageBag->getMessages())
                                ->map(fn (array $messages) => array_map(
                                    static fn (string $message) => DashboardMessageTranslator::translateError($message),
                                    $messages
                                ))
                                ->all()
                        );
                        $bag->put($bagName, $translated);
                    }
                    session()->put('errors', $bag);
                }
            }
        }

        return $response;
    }
}
