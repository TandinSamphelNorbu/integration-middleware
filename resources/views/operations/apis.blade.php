@extends('layouts.workspace')
@section('title', 'API directory')
@section('workspace')
<div><p class="text-xs font-semibold uppercase tracking-widest text-teal-700">Integrations</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">API directory</h1><p class="mt-2 text-sm text-slate-500">{{ $apis->count() }} registered endpoints for your middleware.</p></div>
<div class="rounded-2xl border border-teal-100 bg-teal-50 p-5 text-sm leading-6 text-teal-900"><strong>Connecting to the API</strong><p>Sign in with <code>POST /api/v1/login</code>, then send the returned token as <code>Authorization: Bearer &lt;token&gt;</code>. Send <code>Accept: application/json</code> on requests, and JSON bodies for POST requests.</p></div>
<section class="grid gap-5 xl:grid-cols-2" aria-label="API endpoints">
@foreach ($apis as $api)
    <article class="panel flex flex-col p-6"><div class="flex items-center justify-between gap-3"><span class="rounded-md bg-slate-100 px-2.5 py-1 font-mono text-xs font-bold text-teal-700">{{ $api['methods'] }}</span><span class="text-xs text-slate-500">{{ $api['protected'] ? 'Bearer token required' : 'Public · credentials required' }}</span></div><h2 class="mt-5 text-lg font-semibold">{{ $api['details'][0] }}</h2><code class="mt-2 block select-all break-all text-sm text-teal-700">{{ $api['path'] }}</code><p class="mt-3 text-sm leading-6 text-slate-500">{{ $api['details'][1] }}</p><div class="mt-5 border-t border-slate-100 pt-4 text-sm"><span class="text-xs font-semibold uppercase tracking-wider text-slate-400">{{ $api['methods'] === 'GET' ? 'Query parameters' : 'JSON body' }}</span><p class="mt-2 text-slate-600">{{ $api['details'][2] }}</p></div></article>
@endforeach
</section>
@endsection
