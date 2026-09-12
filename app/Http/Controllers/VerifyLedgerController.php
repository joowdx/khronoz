<?php

namespace App\Http\Controllers;

use App\Enums\RenditionStatus;
use App\Http\Resources\DocumentResource;
use App\Models\Agency;
use App\Models\Rendition;
use App\Tenancy\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class VerifyLedgerController extends Controller
{
    public function __invoke(Request $request, string $token, Tenant $tenant): Response
    {
        $rendition = Rendition::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        $agency = Agency::findOrFail($rendition->agency_id);

        return $tenant->within($agency, function () use ($request, $rendition): Response {
            $rendition->load('document');
            $ready = $rendition->status === RenditionStatus::Ready && $rendition->document !== null;
            $response = Inertia::render('ledgers/verify', [
                'snapshot' => $rendition->snapshot,
                'superseded_at' => $rendition->superseded_at?->toDateTimeString(),
                'document' => $ready ? DocumentResource::make($rendition->document)->resolve() : null,
                'download_url' => $ready && $request->user()?->can('download', $rendition)
                    ? route('ledgers.renditions.download', ['ledger' => $rendition->ledger_id, 'rendition' => $rendition->id]) : null,
            ])->toResponse($request);
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        });
    }
}
