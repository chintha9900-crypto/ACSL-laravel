{{--
    Dev's own dashboard (/dev): read-only system health and a recent audit
    trail. Shows no secrets, configuration values or personal data.
--}}
<x-layouts.admin title="Dev Dashboard">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Dev Dashboard</h1>
    <p class="mt-1 text-sm text-muted-foreground">
        Signed in as {{ auth()->user()->name }} &middot; {{ auth()->user()->roleModel?->label ?? auth()->user()->role }}
    </p>

    @if ($health !== null)
        <section class="ui-card mt-6 p-6" aria-labelledby="health-heading">
            <h2 id="health-heading" class="font-display text-lg font-bold text-primary">System health</h2>
            <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($health as $item)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $item['label'] }}</dt>
                        <dd class="mt-1 font-medium {{ $item['ok'] === false ? 'text-destructive' : '' }}">
                            {{ $item['value'] }}
                            @if ($item['ok'] === false)
                                <span class="sr-only">(needs attention)</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    @if ($auditRows !== null)
        <section class="ui-card mt-6 p-6" aria-labelledby="audit-heading">
            <h2 id="audit-heading" class="font-display text-lg font-bold text-primary">Recent audit log</h2>
            <p class="mt-1 text-sm text-muted-foreground">The latest {{ $auditRows->count() }} events. User, IP address and changed values are not shown.</p>

            @if ($auditRows->isEmpty())
                <p class="mt-4 text-sm text-muted-foreground">No audit events recorded yet.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="py-2 pr-4 font-medium">When (UTC)</th>
                                <th class="py-2 pr-4 font-medium">Event</th>
                                <th class="py-2 pr-4 font-medium">Subject</th>
                                <th class="py-2 font-medium">Actor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($auditRows as $row)
                                <tr>
                                    <td class="py-2 pr-4 whitespace-nowrap">{{ $row->created_at }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $row->event }}</td>
                                    <td class="py-2 pr-4">{{ $row->subject_type }} #{{ $row->subject_id }}</td>
                                    <td class="py-2">{{ $row->actor_type }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</x-layouts.admin>
