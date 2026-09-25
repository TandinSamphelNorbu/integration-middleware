@extends('layouts.workspace')
@section('title', 'Plan workspace')
@section('workspace')
            <div><p class="text-xs font-semibold uppercase tracking-widest text-teal-700">Subscriber services</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">Plan workspace</h1><p class="mt-2 text-sm text-slate-500">Look up eligible plans and manage your catalog.</p></div>
            <section class="panel overflow-hidden">
                <div class="border-b border-slate-100 px-6 py-5"><h2 class="font-semibold">Find subscriber plans</h2><p class="mt-1 text-sm text-slate-500">Enter a service number to see the plans available to that subscriber.</p></div>
                <form id="lookup-form" data-catalog-url="{{ route('dashboard.catalog') }}" data-fwa-url="{{ route('dashboard.fwa') }}" class="grid items-end gap-5 p-6 md:grid-cols-[1fr_1.4fr_auto]">
                    <div><label class="field-label" for="plan-source">Service</label><select id="plan-source" class="field"><option value="catalog">Mobile catalog</option><option value="fwa">FWA broadband</option></select></div>
                    <div><label class="field-label" for="service-id">Service number</label><input id="service-id" name="service_id" class="field" required maxlength="20" placeholder="Enter service number" autocomplete="off"></div>
                    <button class="button-primary" type="submit">Find plans <span aria-hidden="true">→</span></button>
                </form>
            </section>
            <section class="panel" aria-labelledby="results-title" aria-busy="false" id="results-panel">
                <div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><h2 id="results-title" class="font-semibold">Available plans</h2><span id="result-count" class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-500">No lookup yet</span></div>
                <p id="lookup-status" role="status" aria-live="polite" class="px-6 pt-5 text-sm text-slate-500"></p>
                <div id="plan-results" class="p-6"><div class="py-12 text-center"><div class="mx-auto mb-4 grid size-12 place-items-center rounded-2xl bg-teal-50 text-2xl text-teal-700" aria-hidden="true">⌕</div><h3 class="font-medium">Your next lookup starts here</h3><p class="mt-2 text-sm text-slate-500">Choose a service and enter a number above.</p></div></div>
            </section>
            <section aria-labelledby="maintenance-title"><div class="mb-4 flex items-center gap-3"><h2 id="maintenance-title" class="text-sm font-semibold">Catalog maintenance</h2><span class="text-xs text-slate-400">Update on demand</span></div>
                <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                    <article class="panel p-6"><h3 class="font-semibold">Refresh mobile catalog</h3><p class="mt-2 text-sm leading-6 text-slate-500">Reload cached plans and student numbers from the source catalog.</p><button type="button" class="button-secondary mt-5" data-action-url="{{ route('dashboard.refresh') }}" data-confirm="Refresh the shared mobile catalog cache now?">Refresh catalog <span aria-hidden="true">↻</span></button><p role="status" aria-live="polite" class="action-status mt-3 text-sm text-slate-500"></p></article>
                    <article class="panel p-6"><h3 class="font-semibold">Sync FWA plans</h3><p class="mt-2 text-sm leading-6 text-slate-500">Update local 4G and 5G plans from the source. Missing plans become inactive.</p><button type="button" class="button-secondary mt-5" data-action-url="{{ route('dashboard.sync') }}" data-confirm="Synchronize FWA plans now? This updates local plans and marks missing source plans inactive.">Sync FWA plans <span aria-hidden="true">↻</span></button><p role="status" aria-live="polite" class="action-status mt-3 text-sm text-slate-500"></p></article>
                    <article class="panel p-6"><h3 class="font-semibold">Refresh ILL cache</h3><p class="mt-2 text-sm leading-6 text-slate-500">Reload ILL base plans and boosters from the source catalog. Offerings are cached for 12 hours.</p><button type="button" class="button-secondary mt-5" data-action-url="{{ route('dashboard.ill.refresh') }}" data-confirm="Refresh the shared ILL offerings cache now?">Refresh ILL cache <span aria-hidden="true">&#8635;</span></button><p role="status" aria-live="polite" class="action-status mt-3 text-sm text-slate-500"></p></article>
                </div>
            </section>
@endsection
