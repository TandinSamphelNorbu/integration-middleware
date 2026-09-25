@extends('layouts.app')

@section('content')
<div class="min-h-screen lg:grid lg:grid-cols-[240px_1fr]">
    <aside class="flex flex-col bg-slate-950 p-6 text-white lg:min-h-screen">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-3 text-xl font-semibold"><span class="grid size-9 place-items-center rounded-xl bg-teal-400 text-slate-950">T</span>TashiCell</a>
        <p class="mt-2 pl-12 text-xs text-slate-500">Integration workspace</p>
        <nav aria-label="Main navigation" class="mt-8 space-y-2 lg:mt-12">
            @foreach (['dashboard' => 'Plan workspace', 'ill' => 'ILL offerings', 'logs' => 'Request logs', 'apis' => 'API directory'] as $routeName => $label)
                <a href="{{ route($routeName) }}" @if(request()->routeIs($routeName)) aria-current="page" @endif @class(['flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-medium transition', 'bg-white/10 text-teal-300' => request()->routeIs($routeName), 'text-slate-400 hover:bg-white/5 hover:text-white' => !request()->routeIs($routeName)])>{{ $label }}</a>
            @endforeach
        </nav>
        <div class="mt-auto hidden pt-16 lg:block"><div class="border-t border-white/10 pt-5 text-xs leading-6 text-slate-500">Subscriber services<br><span class="text-slate-300">Mobile & fixed wireless</span></div></div>
    </aside>
    <div class="min-w-0">
        <header class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-white px-6 py-5 lg:px-10"><p class="text-sm text-slate-500">Operations <span class="mx-3 text-slate-300">/</span> <span class="text-slate-800">@yield('title')</span></p><div class="flex items-center gap-4"><span class="text-sm font-medium">{{ auth()->user()->name }}</span><form method="POST" action="{{ route('logout') }}">@csrf<button class="text-sm text-slate-500 hover:text-slate-950" type="submit">Sign out</button></form></div></header>
        <main class="mx-auto max-w-7xl space-y-7 p-6 lg:p-10">
            @yield('workspace')
            <footer class="text-xs text-slate-400">TashiCell operations workspace</footer>
        </main>
    </div>
</div>
@endsection
