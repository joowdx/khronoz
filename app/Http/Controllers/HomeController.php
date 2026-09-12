<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('home', [

            // invented here and each install answers on its own address; the
            // two "Request a demo" buttons above the closing section are
            // anchors to it rather than a second copy of the address.
            'demo' => 'mailto:'.Config::string('mail.from.address').'?subject='.rawurlencode('khronoz demo request'),
        ]);
    }
}
