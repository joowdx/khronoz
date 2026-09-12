<?php

namespace App\Http\Middleware;

use App\Support\Legal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLegalAcceptance
{
    public function __construct(private Legal $legal) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->legal->isAcceptedBy($request->user()) || (app()->isProduction() && ! $this->legal->isPublished())) {
            if ($request->isMethod('GET')) {
                $request->session()->put('legal.intended', $request->getRequestUri());
            }

            return redirect()->route('legal.acceptance.create');
        }

        return $next($request);
    }
}
