<?php

namespace App\Http\Controllers;

use App\Support\Legal;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Head\Facades\Head;

class LegalController extends Controller
{
    public function __invoke(Legal $legal, string $document, ?string $version = null): Response
    {
        $record = $legal->document($document, $version);
        Head::title($record['title']);

        return Inertia::render('legal/show', [
            'document' => $record,
        ]);
    }
}
