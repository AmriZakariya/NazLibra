@extends('castlit.layout')

@section('title', 'Nouveau client — Administration Castl-it-POS')

@section('content')
<style>
    .create { padding: 34px 0 0; max-width: 720px; margin: 0 auto; }
    .back { color: var(--muted); font-size: 13.5px; font-weight: 600; }
    .create h1 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; margin: 12px 0 4px; }
    .create .sub { color: var(--muted); font-size: 14px; }
    .card { background: var(--surface); border: 1px solid var(--sand); border-radius: 16px; box-shadow: var(--shadow); margin-top: 22px; }
    .card .body { padding: 26px; }
    .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .fld { display: flex; flex-direction: column; gap: 6px; margin-bottom: 16px; }
    .fld label { font-size: 12.5px; font-weight: 700; }
    .fld .req { color: var(--brand); }
    .fld input, .fld select { font: inherit; font-size: 14.5px; padding: 11px 13px; border-radius: 10px; border: 1.5px solid var(--sand); background: var(--paper); color: var(--ink); width: 100%; }
    .fld input:focus, .fld select:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 14%, transparent); }
    .subdomain-row { display: flex; align-items: stretch; border: 1.5px solid var(--sand); border-radius: 10px; overflow: hidden; background: var(--paper); }
    .subdomain-row:focus-within { border-color: var(--brand); box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 14%, transparent); }
    .subdomain-row input { border: none; box-shadow: none; background: transparent; text-align: right; }
    .subdomain-suf { display: flex; align-items: center; padding: 0 13px; font-size: 13.5px; color: var(--muted); background: color-mix(in srgb, var(--sand) 40%, transparent); white-space: nowrap; }
    .info { background: color-mix(in srgb, var(--brand) 8%, transparent); border: 1px solid color-mix(in srgb, var(--brand) 22%, transparent); color: var(--ink); border-radius: 10px; padding: 12px 14px; font-size: 13.5px; margin-bottom: 20px; }
    @media (max-width: 560px) { .grid2 { grid-template-columns: 1fr; } }
</style>

<main class="create">
    <div class="wrap">
        <a href="{{ route('castlit.admin.index') }}" class="back">← Toutes les demandes</a>
        <h1>Nouveau client</h1>
        <p class="sub">Pour une demande reçue par WhatsApp, formulaire Google ou téléphone. Le client est créé approuvé et son espace est provisionné immédiatement.</p>

        @if ($errors->any())
            <div class="flash flash-err" style="margin-top:18px">Merci de corriger les champs indiqués ci-dessous.</div>
        @endif

        <div class="card">
            <div class="body">
                @if (config('castlit.provision.subdomain_manual'))
                    <div class="info" style="background:var(--warn-bg); border-color:color-mix(in srgb, var(--warn) 30%, transparent); color:var(--warn)">
                        <strong>Étape préalable (une fois) :</strong> créez d'abord le sous-domaine
                        <strong>&lt;sous-domaine&gt;.{{ config('castlit.main_domain') }}</strong> dans le panneau LWS
                        (dossier <code>~/&lt;sous-domaine&gt;.{{ config('castlit.main_domain') }}</code>), puis enregistrez ici :
                        l'installation remplit ce dossier automatiquement.
                    </div>
                @endif
                <div class="info">L'enregistrement lance aussitôt le provisioning de <strong>sousdomaine.{{ config('castlit.main_domain') }}</strong> et envoie les accès au client par email.</div>

                <form method="POST" action="{{ route('castlit.admin.store') }}" novalidate>
                    @csrf

                    <div class="fld">
                        <label for="business_name">Nom du commerce <span class="req">*</span></label>
                        <input id="business_name" name="business_name" required maxlength="120" value="{{ old('business_name') }}" placeholder="Ex : Librairie Al Manara">
                        @error('business_name')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="grid2">
                        <div class="fld">
                            <label for="activity">Activité</label>
                            <select id="activity" name="activity">
                                <option value="">Choisir…</option>
                                @foreach ($activities as $key => $label)
                                    <option value="{{ $key }}" @selected(old('activity') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('activity')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="fld">
                            <label for="currency">Devise <span class="req">*</span></label>
                            <select id="currency" name="currency" required>
                                @foreach ($currencies as $c)
                                    <option value="{{ $c }}" @selected(old('currency', 'MAD') === $c)>{{ $c }}</option>
                                @endforeach
                            </select>
                            @error('currency')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="fld">
                        <label for="desired_subdomain">Adresse (sous-domaine) <span class="req">*</span></label>
                        <div class="subdomain-row">
                            <input id="desired_subdomain" name="desired_subdomain" required pattern="[a-z0-9]{2,30}" maxlength="30" autocomplete="off" spellcheck="false" value="{{ old('desired_subdomain') }}" placeholder="almanara" oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9]/g,'')">
                            <span class="subdomain-suf">.{{ config('castlit.main_domain') }}</span>
                        </div>
                        @error('desired_subdomain')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="grid2">
                        <div class="fld">
                            <label for="contact_name">Nom du contact <span class="req">*</span></label>
                            <input id="contact_name" name="contact_name" required maxlength="120" value="{{ old('contact_name') }}" placeholder="Prénom Nom">
                            @error('contact_name')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                        <div class="fld">
                            <label for="phone">Téléphone</label>
                            <input id="phone" name="phone" maxlength="40" value="{{ old('phone') }}" placeholder="06 00 00 00 00">
                            @error('phone')<span class="field-error">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="fld">
                        <label for="email">Email <span class="req">*</span></label>
                        <input id="email" name="email" type="email" required maxlength="190" value="{{ old('email') }}" placeholder="client@exemple.com">
                        @error('email')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <div class="fld">
                        <label for="heard_about">Source de la demande</label>
                        <input id="heard_about" name="heard_about" maxlength="120" value="{{ old('heard_about', 'WhatsApp') }}" placeholder="WhatsApp, formulaire Google, téléphone…">
                        @error('heard_about')<span class="field-error">{{ $message }}</span>@enderror
                    </div>

                    <button type="submit" class="btn btn-primary" style="width:100%; margin-top:6px"
                            onclick="return confirm('Créer le client et lancer le provisioning ?')">
                        Créer le client &amp; provisionner →
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>
@endsection
