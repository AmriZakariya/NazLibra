@extends('castlit.layout')

@section('title', 'Mises à jour de l’app — Administration Castl-it-POS')

@section('content')
<style>
    .admin { padding: 32px 0 40px; }
    .admin .wrap { max-width: 1040px; }
    .admin-head { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
    .admin-head h1 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; }
    .admin-head p { color: var(--muted); font-size: 14px; max-width: 620px; }
    .plats { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 18px; align-items: start; }
    .card { background: var(--surface); border: 1px solid var(--sand); border-radius: var(--radius); box-shadow: var(--shadow); }
    .card h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); padding: 15px 20px; border-bottom: 1px solid var(--sand); display: flex; justify-content: space-between; gap: 8px; }
    .card form { padding: 16px 20px 20px; display: flex; flex-direction: column; gap: 14px; }
    .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    label.fld { display: flex; flex-direction: column; gap: 5px; font-size: 13px; font-weight: 650; }
    label.fld small, .hint { color: var(--muted); font-weight: 500; font-size: 12px; line-height: 1.45; }
    .fld input[type=text], .fld input[type=url], .fld textarea { font: inherit; font-size: 14px; padding: 9px 12px; border-radius: 10px; border: 1.5px solid var(--sand); background: var(--surface); color: var(--ink); }
    .fld input:focus, .fld textarea:focus { outline: none; border-color: var(--brand); }
    .fld .err { color: var(--err); font-size: 12px; font-weight: 600; }
    .sub { border-top: 1px dashed var(--sand); padding-top: 14px; display: flex; flex-direction: column; gap: 12px; }
    .sub h3 { font-size: 13px; font-weight: 800; }
    .apk-now { font-size: 13px; background: color-mix(in srgb, var(--brand) 6%, transparent); border-radius: 10px; padding: 10px 12px; line-height: 1.5; overflow-wrap: anywhere; }
    .warn { color: var(--warn); font-weight: 650; }
    .store { font-size: 12.5px; overflow-wrap: anywhere; }
    .store.missing { color: var(--err); font-weight: 650; }
    .rules { font-size: 13px; color: var(--muted); line-height: 1.6; margin: 0; padding-left: 18px; }
    @media (max-width: 520px) { .row2 { grid-template-columns: 1fr; } }
</style>
<main class="admin">
    <div class="wrap">
        <div class="admin-head">
            <div>
                <h1>Mises à jour de l’app</h1>
                <p>Sous la <b>version minimale</b>, l’app est bloquée jusqu’à la mise à jour. Sous la <b>dernière version</b>,
                    elle la propose une fois par jour, jamais pendant une vente. Laissez vide pour ne rien imposer.</p>
            </div>
            <div style="margin-left:auto; display:flex; gap:10px; flex-wrap:wrap">
                <a href="{{ route('castlit.admin.clients') }}" class="btn btn-ghost" style="padding:11px 18px">Clients</a>
            </div>
        </div>

        @if (session('success'))<div class="flash flash-ok">{{ session('success') }}</div>@endif
        @if (session('error'))<div class="flash flash-err">{{ session('error') }}</div>@endif

        <div class="plats">
            @foreach ($policies as $platform => $policy)
                @php $mine = old('platform') === $platform; @endphp
                <section class="card" data-platform="{{ $platform }}">
                    <h2>{{ $platform === 'ios' ? 'iOS (iPhone, iPad)' : 'Android' }}
                        @if ($policy->updated_at)<span style="text-transform:none; letter-spacing:0">modifié {{ $policy->updated_at->locale('fr')->diffForHumans() }}</span>@endif
                    </h2>
                    <form method="POST" action="{{ route('castlit.admin.app-updates.save') }}" enctype="multipart/form-data">
                        @csrf
                        <input type="hidden" name="platform" value="{{ $platform }}">
                        <div class="row2">
                            <label class="fld">Dernière version
                                <input type="text" name="latest_version" placeholder="1.0.10" inputmode="decimal"
                                       value="{{ $mine ? old('latest_version') : $policy->latest_version }}">
                                <small>Proposée (mise à jour conseillée).</small>
                                @if ($mine) @error('latest_version')<span class="err">{{ $message }}</span>@enderror @endif
                            </label>
                            <label class="fld">Version minimale
                                <input type="text" name="min_version" placeholder="1.0.8" inputmode="decimal"
                                       value="{{ $mine ? old('min_version') : $policy->min_version }}">
                                <small>En dessous : mise à jour obligatoire.</small>
                                @if ($mine) @error('min_version')<span class="err">{{ $message }}</span>@enderror @endif
                            </label>
                        </div>
                        <label class="fld">Message (facultatif)
                            <textarea name="message" rows="2" maxlength="500" placeholder="Ex. : corrige l’impression des tickets.">{{ $mine ? old('message') : $policy->message }}</textarea>
                        </label>

                        <div class="hint">Lien du store :
                            @if ($storeUrls[$platform])
                                <a class="store" href="{{ $storeUrls[$platform] }}" target="_blank" rel="noopener">{{ $storeUrls[$platform] }}</a>
                            @else
                                <span class="store missing" data-store-missing>non défini ({{ $platform === 'ios' ? 'CASTLIT_APP_STORE_URL' : 'CASTLIT_PLAY_STORE_URL' }} dans le .env)</span>
                            @endif
                        </div>

                        @if ($platform === 'android')
                            <div class="sub">
                                <h3>APK pour les appareils sans Google Play (PAX)</h3>
                                @if ($policy->apk_path)
                                    <div class="apk-now" data-apk-now>
                                        APK en ligne : <b>v{{ $policy->apk_version ?: '?' }}</b>
                                        · {{ number_format(($policy->apk_size ?? 0) / 1048576, 1, ',', ' ') }} Mo
                                        · <a href="{{ \App\Support\AppVersions::apkUrl($policy) }}">télécharger</a>
                                        @if ($policy->latest_version && $policy->apk_version && \App\Support\AppVersions::compare($policy->apk_version, $policy->latest_version) < 0)
                                            <div class="warn" data-apk-old>Plus ancien que la dernière version : les PAX ne pourront pas se mettre à jour.</div>
                                        @endif
                                        <label style="display:flex; gap:6px; align-items:center; margin-top:6px; font-size:12.5px">
                                            <input type="checkbox" name="remove_apk" value="1"> Retirer cet APK
                                        </label>
                                    </div>
                                @endif
                                <label class="fld">{{ $policy->apk_path ? 'Remplacer l’APK' : 'Déposer l’APK' }}
                                    <input type="file" name="apk" accept=".apk,application/vnd.android.package-archive">
                                    <small>Limite de ce serveur : {{ $uploadLimit }}. Au-delà, collez plutôt un lien.</small>
                                    @if ($mine) @error('apk')<span class="err">{{ $message }}</span>@enderror @endif
                                </label>
                                <div class="row2">
                                    <label class="fld">Version de l’APK
                                        <input type="text" name="apk_version" placeholder="= dernière version"
                                               value="{{ $mine ? old('apk_version') : $policy->apk_version }}">
                                        @if ($mine) @error('apk_version')<span class="err">{{ $message }}</span>@enderror @endif
                                    </label>
                                    <label class="fld">…ou lien vers l’APK
                                        <input type="url" name="apk_url" placeholder="https://…/castlitpos.apk"
                                               value="{{ $mine ? old('apk_url') : $policy->apk_url }}">
                                        @if ($mine) @error('apk_url')<span class="err">{{ $message }}</span>@enderror @endif
                                    </label>
                                </div>
                                <ul class="rules">
                                    <li>Installée depuis Google Play : l’app ouvre le Play Store.</li>
                                    <li>Sinon (PAX) : elle télécharge cet APK et ouvre l’installation.</li>
                                </ul>
                            </div>
                        @endif

                        <button class="btn btn-primary" type="submit" style="align-self:flex-start">Enregistrer</button>
                    </form>
                </section>
            @endforeach
        </div>
    </div>
</main>
@endsection
