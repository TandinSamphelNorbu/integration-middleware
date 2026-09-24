@extends('layouts.workspace')
@section('title', 'Request logs')
@section('workspace')
<div><p class="text-xs font-semibold uppercase tracking-widest text-teal-700">Activity</p><h1 class="mt-2 text-3xl font-semibold tracking-tight">Request logs</h1><p class="mt-2 text-sm text-slate-500">Recorded API traffic, newest first. Times shown in {{ config('app.timezone') }}.</p></div>
<section class="panel p-6">
    <form method="GET" action="{{ route('logs') }}" class="grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-[2fr_1fr_1fr_1fr_auto]">
        <div><label for="search" class="field-label">Path or request ID</label><input class="field" id="search" name="search" maxlength="100" value="{{ request('search') }}" placeholder="api/v1/catalog"></div>
        <div><label for="method" class="field-label">Method</label><select class="field" id="method" name="method"><option value="">All methods</option>@foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'] as $method)<option @selected(request('method') === $method)>{{ $method }}</option>@endforeach</select></div>
        <div><label for="status" class="field-label">Status</label><select class="field" id="status" name="status"><option value="">All statuses</option>@foreach (['2' => '2xx · Success', '3' => '3xx · Redirect', '4' => '4xx · Client error', '5' => '5xx · Server error'] as $code => $label)<option value="{{ $code }}" @selected((string) request('status') === (string) $code)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="date" class="field-label">Date</label><input class="field" id="date" name="date" type="date" value="{{ request('date') }}"></div>
        <button class="button-primary" type="submit">Apply filters</button>
    </form>
    @if ($errors->any())<p role="alert" class="mt-3 text-sm text-red-700">{{ $errors->first() }}</p>@endif
    <a href="{{ route('logs') }}" class="mt-4 inline-block text-xs font-medium text-teal-700">Clear filters / reload</a>
</section>
<section class="panel overflow-hidden">
    <div class="flex items-center justify-between border-b border-slate-100 px-6 py-5"><h2 class="font-semibold">Request history</h2><span class="text-sm text-slate-500">{{ number_format($logs->total()) }} requests</span></div>
    <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="px-6 py-4">Time</th><th class="px-4 py-4">Method / path</th><th class="px-4 py-4">Status</th><th class="px-4 py-4">Duration</th><th class="px-4 py-4">Client / user</th><th class="px-6 py-4">Request ID</th></tr></thead><tbody class="divide-y divide-slate-100">
    @forelse ($logs as $log)
        <tr class="hover:bg-slate-50/70"><td class="whitespace-nowrap px-6 py-4 text-slate-500">{{ $log->requested_at->format('M d, Y H:i:s') }}</td><td class="px-4 py-4"><span class="text-xs font-bold text-teal-700">{{ $log->method }}</span><p class="mt-1 max-w-xs break-all font-mono text-xs">/{{ $log->path }}</p></td><td class="px-4 py-4"><span @class(['rounded-full px-2.5 py-1 text-xs font-semibold', 'bg-red-50 text-red-700' => $log->status_code >= 400, 'bg-teal-50 text-teal-700' => $log->status_code < 400])>{{ $log->status_code ?? '—' }}</span></td><td class="whitespace-nowrap px-4 py-4 text-slate-500">{{ $log->duration_ms ?? '—' }} ms</td><td class="px-4 py-4 text-xs text-slate-500">{{ $log->ip_address ?? '—' }}<p class="mt-1">{{ $log->user_id ? 'User #'.$log->user_id : 'Guest' }}</p></td><td class="px-6 py-4 font-mono text-xs text-slate-400"><span class="select-all">{{ $log->request_id }}</span></td></tr>
    @empty
        <tr><td colspan="6" class="px-6 py-16 text-center"><p class="font-medium">No requests found</p><p class="mt-2 text-sm text-slate-500">Try different filters. New API calls will appear here after they are recorded.</p></td></tr>
    @endforelse
    </tbody></table></div>
    @if ($logs->hasPages())<div class="border-t border-slate-100 p-6">{{ $logs->links() }}</div>@endif
</section>
@endsection
