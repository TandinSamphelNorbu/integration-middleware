@extends('layouts.workspace')
@section('title', 'ILL offerings')
@section('workspace')
<div class="flex flex-wrap items-start justify-between gap-4">
    <div><p class="text-xs font-semibold uppercase tracking-widest text-teal-700">Leased-line services</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">ILL offerings</h1><p class="mt-2 text-sm text-slate-500">Find the optional add-ons mapped to an ILL base plan.</p></div>
    <a href="{{ route('dashboard') }}" class="button-secondary">Find subscriber plans &rarr;</a>
</div>
<section class="panel p-6" aria-labelledby="mapping-search-title">
    <h2 id="mapping-search-title" class="font-semibold">Look up a base plan</h2>
    <p class="mt-2 text-sm text-slate-500">This lookup shows configured offerings. To check subscriber eligibility, choose ILL leased line in the plan workspace.</p>
    <form method="GET" action="{{ route('ill') }}" class="mt-5 grid items-end gap-5 md:grid-cols-[1fr_1.5fr_auto]">
        <div><label for="base-plan-id" class="field-label">Base plan ID</label><input id="base-plan-id" name="base_plan_id" class="field" value="{{ $basePlanId }}" required maxlength="50" aria-describedby="base-id-help"><p id="base-id-help" class="mt-2 text-xs text-slate-500">For example, 109.</p></div>
        <div><label for="base-plan-name" class="field-label">Base plan name <span class="font-normal text-slate-400">(optional)</span></label><input id="base-plan-name" name="base_plan_name" class="field" value="{{ $basePlanName }}" maxlength="255" placeholder="ILL Main Offering"><p class="mt-2 text-xs text-slate-500">If supplied, the name must match this base plan.</p></div>
        <button type="submit" class="button-primary">Find add-ons &rarr;</button>
    </form>
    @if ($lookupErrors)
        <div role="alert" class="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            @foreach ($lookupErrors as $messages)
                @foreach ($messages as $message)<p>{{ $message }}</p>@endforeach
            @endforeach
        </div>
    @endif
</section>
@if ($catalog)
<section class="panel overflow-hidden" aria-labelledby="addons-title">
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 p-6">
        <div><p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Base plan {{ $catalog['base_plan']['id'] }}</p><h2 id="addons-title" class="mt-2 text-lg font-semibold">{{ $catalog['base_plan']['name'] ?? 'Unmapped base plan' }}</h2></div>
        <span class="rounded-full bg-teal-50 px-3 py-1 text-sm text-teal-700">{{ count($catalog['addons']) }} add-ons</span>
    </div>
    @if ($catalog['addons'])
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><caption class="sr-only">Optional add-ons for base plan {{ $catalog['base_plan']['id'] }}</caption><thead class="bg-slate-50 text-slate-500"><tr><th scope="col" class="px-6 py-4 font-medium">Add-on ID</th><th scope="col" class="px-6 py-4 font-medium">Offering name</th></tr></thead><tbody class="divide-y divide-slate-100">
        @foreach ($catalog['addons'] as $addon)
            <tr><td class="whitespace-nowrap px-6 py-4 font-mono text-teal-700">{{ $addon['id'] }}</td><td class="px-6 py-4 font-medium">{{ $addon['name'] }}</td></tr>
        @endforeach
        </tbody></table></div>
    @else
        <div class="px-6 py-12 text-center"><h3 class="font-medium">No add-ons mapped</h3><p class="mt-2 text-sm text-slate-500">Check the base plan ID or ask your administrator to configure its mappings.</p></div>
    @endif
</section>
@endif
<p class="text-sm text-slate-500">Offerings are available for reference only. No subscription or activation is performed here.</p>
@endsection
