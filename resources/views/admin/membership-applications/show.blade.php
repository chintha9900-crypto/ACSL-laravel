@use('App\Models\MembershipApplication')

<x-layouts.admin :title="'Application '.$application->public_id">
    <a href="{{ route('admin.membership-applications.index') }}" class="text-sm text-primary underline underline-offset-2 hover:text-secondary">&larr; All applications</a>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">{{ $application->full_name }}</h1>
            <p class="mt-1 break-all font-mono text-xs text-muted-foreground">{{ $application->public_id }}</p>
        </div>
        <x-admin.status-badge :status="$application->status" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="ui-card p-6" aria-labelledby="applicant-heading">
                <h2 id="applicant-heading" class="font-display text-lg font-bold text-primary">Applicant</h2>
                <dl class="mt-4 grid gap-x-6 gap-y-4 text-sm md:grid-cols-2">
                    @php
                        $rows = [
                            'Category' => $application->category->name,
                            'Submitted' => $application->submitted_at->format('j F Y, H:i').' UTC',
                            'Email' => $application->email,
                            'Mobile' => $application->mobile,
                            'Address' => $application->address,
                            'Aviation role' => $application->aviation_role,
                            'Organisation' => $application->aviation_organisation,
                        ];

                        if ($application->study_start_date) {
                            $rows['Course start date'] = $application->study_start_date->format('j F Y');
                        }
                        if ($application->expected_completion_date) {
                            $rows['Expected completion date'] = $application->expected_completion_date->format('j F Y');
                        }
                        if ($application->years_experience !== null) {
                            $rows['Years of experience'] = $application->years_experience;
                        }
                        if ($application->previous_employers) {
                            $rows['Previous employers'] = $application->previous_employers;
                        }
                    @endphp

                    @foreach ($rows as $label => $value)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $label }}</dt>
                            <dd class="mt-1 whitespace-pre-line break-words">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section class="ui-card p-6" aria-labelledby="proof-heading">
                <h2 id="proof-heading" class="font-display text-lg font-bold text-primary">Aviation proof</h2>

                @forelse ($application->documents->whereNull('membership_details_request_id') as $document)
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border px-4 py-3 text-sm">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ $document->original_filename }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ $document->mime_type }} &middot; {{ number_format($document->size_bytes / 1024, 1) }} KB &middot; uploaded {{ $document->created_at->format('j M Y') }}
                            </p>
                        </div>
                        @if ($document->purged_at)
                            <span class="text-xs text-muted-foreground">Removed</span>
                        @else
                            <a href="{{ route('admin.documents.show', $document) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Download</a>
                        @endif
                    </div>
                @empty
                    <p class="mt-3 text-sm text-muted-foreground">No documents.</p>
                @endforelse
            </section>

            @if ($application->detailsRequests->isNotEmpty())
                <section class="ui-card p-6" aria-labelledby="details-heading">
                    <h2 id="details-heading" class="font-display text-lg font-bold text-primary">More details</h2>

                    @foreach ($application->detailsRequests as $detailsRequest)
                        <div class="mt-4 space-y-3 rounded-lg border border-border px-4 py-3 text-sm">
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Requested {{ $detailsRequest->requested_at->format('j F Y, H:i') }} UTC</p>
                                <p class="mt-1 whitespace-pre-line break-words">{{ $detailsRequest->request_message }}</p>
                            </div>

                            @if ($detailsRequest->responded_at)
                                <div class="border-t border-border pt-3">
                                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Applicant response {{ $detailsRequest->responded_at->format('j F Y, H:i') }} UTC</p>
                                    @if ($detailsRequest->response_message)
                                        <p class="mt-1 whitespace-pre-line break-words">{{ $detailsRequest->response_message }}</p>
                                    @endif

                                    @foreach ($detailsRequest->documents as $document)
                                        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
                                            <p class="min-w-0 truncate">{{ $document->original_filename }}
                                                <span class="text-xs text-muted-foreground">&middot; {{ $document->mime_type }} &middot; {{ number_format($document->size_bytes / 1024, 1) }} KB</span>
                                            </p>
                                            @if ($document->purged_at)
                                                <span class="text-xs text-muted-foreground">Removed</span>
                                            @else
                                                <a href="{{ route('admin.documents.show', $document) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Download</a>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <p class="border-t border-border pt-3 text-muted-foreground">Waiting for the applicant's response.</p>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endif
        </div>

        <aside class="ui-card h-fit p-6" aria-labelledby="decision-heading">
            <h2 id="decision-heading" class="font-display text-lg font-bold text-primary">Decision</h2>

            @if ($application->status === MembershipApplication::STATUS_SUBMITTED)
                <form method="POST" action="{{ route('admin.membership-applications.review', $application) }}" class="mt-4 space-y-4">
                    @csrf

                    @error('decision')
                        <p class="rounded-lg border border-destructive/50 px-3 py-2 text-xs text-destructive" role="alert">{{ $message }}</p>
                    @enderror

                    <fieldset class="space-y-2">
                        <legend class="sr-only">Decision</legend>
                        @foreach ([
                            MembershipApplication::STATUS_APPROVED => 'Approved',
                            MembershipApplication::STATUS_REJECTED => 'Declined',
                            MembershipApplication::STATUS_MORE_DETAILS_REQUIRED => 'More Details Required',
                        ] as $value => $label)
                            <label class="flex cursor-pointer items-center gap-2 text-sm">
                                <input type="radio" name="decision" value="{{ $value }}" @checked(old('decision') === $value) required>
                                {{ $label }}
                            </label>
                        @endforeach
                    </fieldset>

                    <div class="space-y-1.5">
                        <label for="request_message" class="block text-sm font-medium leading-none">Message to the applicant (required for More Details Required)</label>
                        <textarea id="request_message" name="request_message" rows="3" maxlength="2000" @error('request_message') aria-invalid="true" @enderror class="field-control">{{ old('request_message') }}</textarea>
                        @error('request_message')
                            <p class="text-xs text-destructive">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-1.5">
                        <label for="note" class="block text-sm font-medium leading-none">Admin note (internal)</label>
                        <textarea id="note" name="note" rows="3" maxlength="2000" @error('note') aria-invalid="true" @enderror class="field-control">{{ old('note') }}</textarea>
                        @error('note')
                            <p class="text-xs text-destructive">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-1.5">
                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" name="proof_reviewed" value="1" class="mt-0.5" @checked(old('proof_reviewed'))>
                            <span>I have reviewed the submitted aviation proof (required to approve).</span>
                        </label>
                        @error('proof_reviewed')
                            <p class="text-xs text-destructive">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-lg btn-gradient w-full">Record decision</button>
                </form>
            @else
                <p class="mt-4 text-sm text-muted-foreground">
                    @if ($application->status === MembershipApplication::STATUS_MORE_DETAILS_REQUIRED)
                        Waiting for the applicant to respond to the request for more details.
                    @else
                        This application has been decided and cannot be changed here.
                    @endif
                </p>

                @if ($application->decided_at)
                    <p class="mt-3 text-xs text-muted-foreground">Decided {{ $application->decided_at->format('j F Y, H:i') }} UTC</p>
                @endif
            @endif

            @if ($application->decision_note)
                <div class="mt-4 border-t border-border pt-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Admin note</p>
                    <p class="mt-1 whitespace-pre-line break-words text-sm">{{ $application->decision_note }}</p>
                </div>
            @endif

            @if ($application->status === MembershipApplication::STATUS_APPROVED)
                <div class="mt-6 border-t border-border pt-6" aria-labelledby="membership-heading">
                    <h2 id="membership-heading" class="font-display text-lg font-bold text-primary">Membership</h2>

                    @if ($application->membership)
                        @php($term = $application->membership->terms->first())
                        <dl class="mt-4 space-y-3 text-sm">
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Membership number</dt>
                                <dd class="mt-1 font-mono text-base font-semibold">{{ $application->membership->membership_number }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Activated</dt>
                                <dd class="mt-1">{{ $application->membership->activated_on->format('j F Y') }}</dd>
                            </div>
                            @if ($term)
                                <div>
                                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Introductory term</dt>
                                    <dd class="mt-1">{{ $term->duration_months }} months, free &middot; {{ $term->starts_on->format('j M Y') }} to {{ $term->expires_on->format('j M Y') }}</dd>
                                </div>
                            @endif
                        </dl>

                        @if ($application->membership->user->status === 'pending_setup')
                            <form method="POST" action="{{ route('admin.membership-applications.setup-link', $application) }}" class="mt-4 space-y-3 border-t border-border pt-4">
                                @csrf

                                @error('setup_link')
                                    <p class="rounded-lg border border-destructive/50 px-3 py-2 text-xs text-destructive" role="alert">{{ $message }}</p>
                                @enderror

                                <p class="text-sm text-muted-foreground">The member has not yet created their password. Sending a new link cancels any earlier one.</p>
                                <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Resend setup link</button>
                            </form>
                        @endif
                    @else
                        <form method="POST" action="{{ route('admin.membership-applications.activate', $application) }}" class="mt-4 space-y-4">
                            @csrf

                            @error('activation')
                                <p class="rounded-lg border border-destructive/50 px-3 py-2 text-xs text-destructive" role="alert">{{ $message }}</p>
                            @enderror

                            <p class="text-sm text-muted-foreground">
                                Activating creates the member's account (pending setup), issues their permanent membership number and starts the free introductory term. It cannot be undone.
                            </p>

                            <div class="space-y-1.5">
                                <label class="flex items-start gap-2 text-sm">
                                    <input type="checkbox" name="confirm" value="1" class="mt-0.5" required>
                                    <span>I confirm that this membership should be activated now.</span>
                                </label>
                                @error('confirm')
                                    <p class="text-xs text-destructive">{{ $message }}</p>
                                @enderror
                            </div>

                            <button type="submit" class="btn btn-lg btn-gradient w-full">Activate membership</button>
                        </form>
                    @endif
                </div>
            @endif
        </aside>
    </div>
</x-layouts.admin>
