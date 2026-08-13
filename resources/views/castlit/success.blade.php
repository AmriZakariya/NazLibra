@extends('castlit.layout')

@section('title', __('castlit.ok_title').' — Castl-it-POS')

@section('content')
<style>
    .thanks { max-width: 560px; margin: 76px auto 0; text-align: center; padding: 0 24px; }
    .thanks .seal { width: 76px; height: 76px; border-radius: 50%; margin: 0 auto 22px;
                    display: grid; place-items: center; font-size: 34px;
                    background: var(--ok-bg); color: var(--ok); box-shadow: 0 0 0 8px color-mix(in srgb, var(--ok) 8%, transparent); }
    .thanks h1 { font-size: 32px; font-weight: 800; letter-spacing: -.02em; text-wrap: balance; }
    .thanks p { color: var(--muted); font-size: 16px; margin-top: 12px; }
    .addr { display: inline-block; margin-top: 22px; background: var(--surface); border: 1px solid var(--sand);
            border-radius: 12px; padding: 12px 18px; font-weight: 700; box-shadow: var(--shadow); }
    .addr .sd { color: var(--brand); }
    .next { margin-top: 30px; }
</style>

<section class="thanks">
    <div class="seal">✓</div>
    <h1>{{ __('castlit.ok_title') }}</h1>
    <p>{{ __('castlit.ok_sub') }}</p>

    @if ($subdomain)
        <div class="addr">{{ __('castlit.ok_addr') }}&nbsp;: <span class="sd" dir="ltr">{{ $subdomain }}</span>.{{ $mainDomain }}</div>
    @endif

    <div class="next">
        <a href="{{ route('castlit.landing') }}" class="btn btn-ghost">{{ __('castlit.ok_back') }}</a>
    </div>
</section>
@endsection
