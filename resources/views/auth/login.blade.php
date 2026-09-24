@extends('layouts.app')
@section('title', 'Sign in')
@section('content')
<main class="grid min-h-screen lg:grid-cols-2">
    <section class="relative flex flex-col justify-between overflow-hidden bg-slate-950 p-8 text-white lg:p-16">
        <a href="{{ route('login') }}" class="flex items-center gap-3 text-xl font-semibold"><span class="grid size-10 place-items-center rounded-xl bg-teal-400 text-slate-950">T</span> TashiCell <span class="text-sm font-normal text-slate-400">/ Operations</span></a>
        <div class="relative py-16 lg:py-24">
            <p class="mb-5 text-xs font-semibold uppercase tracking-[0.25em] text-teal-300">Integration workspace</p>
            <h1 class="max-w-lg text-4xl font-semibold leading-tight tracking-tight lg:text-6xl">One place.<br>Every connection.</h1>
            <p class="mt-6 max-w-md text-base leading-7 text-slate-400">Find subscriber plans and keep your catalog in sync, from one simple workspace.</p>
            <div class="mt-10 flex flex-wrap gap-3 text-xs text-slate-300"><span class="rounded-full border border-white/15 px-4 py-2">Mobile catalog</span><span class="rounded-full border border-white/15 px-4 py-2">4G & 5G FWA</span><span class="rounded-full border border-white/15 px-4 py-2">Catalog sync</span></div>
        </div>
        <p class="text-xs text-slate-500">TashiCell · Integration middleware</p>
    </section>
    <section class="flex items-center justify-center px-6 py-16">
        <div class="w-full max-w-sm">
            <p class="mb-3 text-xs font-semibold uppercase tracking-widest text-teal-700">Welcome back</p>
            <h2 class="text-3xl font-semibold tracking-tight">Sign in to your workspace</h2>
            <p class="mt-3 text-sm leading-6 text-slate-500">Use your existing middleware account to continue.</p>
            <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5">
                @csrf
                @if ($errors->any())<div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{{ $errors->first() }}</div>@endif
                <div><label for="name" class="field-label">Username</label><input id="name" name="name" value="{{ old('name') }}" required maxlength="255" autofocus autocomplete="username" class="field" placeholder="Enter your username"></div>
                <div><label for="password" class="field-label">Password</label><input id="password" name="password" type="password" required autocomplete="current-password" class="field" placeholder="Enter your password"></div>
                <button class="button-primary w-full" type="submit">Sign in <span aria-hidden="true">→</span></button>
            </form>
            <p class="mt-8 text-center text-xs leading-6 text-slate-500">Need access? Contact your administrator.</p>
        </div>
    </section>
</main>
@endsection
