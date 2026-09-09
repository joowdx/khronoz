<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public marketing page at `/`, server-rendered so a crawler and a link
 * preview both get the real document. It is reachable by anyone and reads
 * nothing: the roster, calendar and CS Form 48 fragments it renders are static
 * markup until Milestone 3 has real rosters to feed them.
 */
class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('home', [
            // The one address on the page. Taking it from the mailbox this
            // deployment already sends its own mail from means no mailbox is
            // invented here and each install answers on its own address; the
            // two "Request a demo" buttons above the closing section are
            // anchors to it rather than a second copy of the address.
            'demo' => 'mailto:'.Config::string('mail.from.address').'?subject='.rawurlencode('khronoz demo request'),
        ]);
    }
}
