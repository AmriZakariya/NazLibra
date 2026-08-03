<?php

namespace App\Http\Controllers\Castlit;

use App\Http\Controllers\Controller;
use App\Support\BusinessMode;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketingController extends Controller
{
    /** Public marketing landing page for castlitpos.com, with the sign-up form. */
    public function landing(): View
    {
        return view('castlit.landing', [
            'activities'  => $this->activityOptions(),
            'currencies'  => ['MAD', 'EUR', 'USD', 'XOF', 'DZD', 'TND'],
            'mainDomain'  => config('castlit.main_domain'),
        ]);
    }

    /** Confirmation page after a subscription request is submitted. */
    public function success(Request $request): View
    {
        return view('castlit.success', [
            'subdomain'  => $request->session()->get('subscribed_subdomain'),
            'mainDomain' => config('castlit.main_domain'),
        ]);
    }

    /** @return array<string,string> business_mode key => human label */
    private function activityOptions(): array
    {
        return collect(BusinessMode::all())
            ->map(fn (array $m) => $m['label'] ?? '')
            ->toArray();
    }
}
