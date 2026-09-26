@use('App\Models\MembershipApplication')

@php
    [$label, $message, $badge] = match ($application->status) {
        MembershipApplication::STATUS_SUBMITTED => [
            'Submitted',
            'We are reviewing your application.',
            'border-secondary/40 bg-secondary/10 text-primary',
        ],
        MembershipApplication::STATUS_MORE_DETAILS_REQUIRED => [
            'More details required',
            'ACI needs additional information to continue reviewing your application. Please contact ACI to provide it.',
            'border-border bg-muted text-foreground',
        ],
        MembershipApplication::STATUS_APPROVED => [
            'Approved',
            'Your application has been approved. Approval and activation are separate steps.',
            'border-transparent bg-primary text-primary-foreground',
        ],
        MembershipApplication::STATUS_REJECTED => [
            'Rejected',
            'Your application has been rejected.',
            'border-destructive/50 text-destructive',
        ],
        default => ['Unknown', 'We could not determine the status of your application.', 'border-border bg-muted text-foreground'],
    };

    if ($openRequest) {
        $message = 'ACI needs additional information to continue reviewing your application.';
    }
@endphp

<x-layouts.public title="Application status">
    <x-public.hero>
        <x-slot:badge>Application Status</x-slot:badge>
        <x-slot:lead>Here is where your membership application stands.</x-slot:lead>
        Track your <span class="text-gradient">application.</span>
    </x-public.hero>

    <section class="container mx-auto max-w-5xl px-4 py-16 md:py-20 lg:px-8">
        <div class="ui-card p-6 md:p-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-display text-2xl font-bold text-primary">Your application</h2>
                <span class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold {{ $badge }}">{{ $label }}</span>
            </div>

            @if (session('status'))
                <div class="mt-6 rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-3 text-sm text-primary" role="status">{{ session('status') }}</div>
            @endif

            <div class="mt-6 rounded-lg border border-border bg-muted px-4 py-3 text-sm" role="status">
                {{ $message }}
            </div>

            <dl class="mt-6 grid gap-x-6 gap-y-4 text-sm md:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Reference</dt>
                    <dd class="mt-1 break-all font-mono">{{ $application->public_id }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Name</dt>
                    <dd class="mt-1">{{ $application->full_name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Membership category</dt>
                    <dd class="mt-1">{{ $application->category->name }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Submitted</dt>
                    <dd class="mt-1">{{ $application->submitted_at->format('j F Y') }}</dd>
                </div>
                @if ($application->decided_at && in_array($application->status, [MembershipApplication::STATUS_APPROVED, MembershipApplication::STATUS_REJECTED], true))
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Decision date</dt>
                        <dd class="mt-1">{{ $application->decided_at->format('j F Y') }}</dd>
                    </div>
                @endif
            </dl>

            @if ($openRequest)
                <div class="mt-8 border-t border-border pt-8">
                    <h3 class="font-display text-lg font-bold text-primary">What ACI has asked for</h3>
                    <p class="mt-2 whitespace-pre-line break-words text-sm">{{ $openRequest->request_message }}</p>

                    <form method="POST" action="{{ $respondUrl }}" enctype="multipart/form-data" class="mt-6 space-y-5">
                        @csrf

                        <x-form.input name="response_message" label="Your reply" type="textarea" rows="4" maxlength="4000" :required="false" />

                        <div class="space-y-1.5">
                            <label for="response_documents" class="block text-sm font-medium leading-none">Additional documents</label>
                            <div class="rounded-lg border border-dashed border-border p-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <p id="response_documents-hint" class="text-xs text-muted-foreground">
                                        Optional. PDF, JPG or PNG. Max {{ round($proof['max_kb'] / 1024, 1) }}MB per file, up to {{ $proof['max_files'] }} files.
                                    </p>
                                    <input
                                        id="response_documents"
                                        name="response_documents[]"
                                        type="file"
                                        multiple
                                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                        aria-describedby="response_documents-hint"
                                        @if ($errors->has('response_documents') || $errors->has('response_documents.*')) aria-invalid="true" @endif
                                        class="min-w-0 max-w-full cursor-pointer text-sm text-muted-foreground file:mr-3 file:h-8 file:cursor-pointer file:rounded-md file:border file:border-input file:bg-background file:px-3 file:text-xs file:font-medium file:text-foreground file:shadow-sm hover:file:bg-accent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                    >
                                </div>
                            </div>
                            @foreach (collect($errors->get('response_documents'))->merge(collect($errors->get('response_documents.*'))->flatten())->unique() as $error)
                                <p class="text-xs text-destructive">{{ $error }}</p>
                            @endforeach
                        </div>

                        <button type="submit" class="btn btn-lg btn-gradient w-full md:w-auto">Send information</button>
                    </form>
                </div>
            @endif

            @if ($application->status === MembershipApplication::STATUS_REJECTED)
                <div class="mt-8">
                    <a href="{{ route('membership.apply') }}" class="btn btn-lg btn-gradient">Apply again</a>
                </div>
            @endif
        </div>
    </section>
</x-layouts.public>
