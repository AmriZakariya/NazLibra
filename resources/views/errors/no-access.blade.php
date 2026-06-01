<!DOCTYPE html>
<html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Accès refusé · LibrairePro</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-50 text-slate-950 antialiased">
        <main class="grid min-h-screen place-items-center px-4 py-10">
            <section class="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
                <span class="mx-auto grid size-12 place-items-center rounded-xl bg-rose-50 text-xl font-bold text-rose-600">!</span>
                <p class="mt-5 text-sm font-semibold uppercase text-rose-600">Accès non autorisé</p>
                <h1 class="mt-2 text-2xl font-semibold">Vous n'avez pas accès à cette section.</h1>
                <p class="mt-3 text-sm leading-6 text-slate-500">
                    Votre compte est bien connecté, mais le rôle actuel ne permet pas d'ouvrir cette page ou d'exécuter cette action.
                    Demandez à un propriétaire ou manager de mettre à jour vos permissions.
                </p>

                @if ($permission)
                    <p class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-500">
                        Permission requise: {{ $permission }}
                    </p>
                @endif

                <div class="mt-6 flex flex-col justify-center gap-3 sm:flex-row">
                    <a href="{{ route('dashboard') }}" class="rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white">Retour au tableau de bord</a>
                    <a href="{{ route('profile') }}" class="rounded-lg border border-slate-200 px-4 py-2.5 text-sm font-semibold text-slate-700">Mon profil</a>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button class="w-full rounded-lg border border-rose-200 px-4 py-2.5 text-sm font-semibold text-rose-600 sm:w-auto">Déconnexion</button>
                    </form>
                </div>
            </section>
        </main>
    </body>
</html>
