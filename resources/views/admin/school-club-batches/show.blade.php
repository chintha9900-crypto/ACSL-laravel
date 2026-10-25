@use('App\Models\SchoolClubStudentBatch')

<x-layouts.admin :title="'Student batch '.$batch->public_id">
    <a href="{{ route('admin.school-club-batches.index') }}" class="text-sm text-primary underline underline-offset-2 hover:text-secondary">← All student batches</a>
    <h1 class="mt-2 font-display text-2xl font-bold text-primary md:text-3xl">School Club student batch</h1>
    <p class="mt-1 break-all font-mono text-xs text-muted-foreground">{{ $batch->public_id }}</p>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="ui-card p-6" aria-labelledby="school-heading">
                <h2 id="school-heading" class="font-display text-lg font-bold text-primary">School Club</h2>
                <dl class="mt-4 grid gap-x-6 gap-y-4 text-sm md:grid-cols-2">
                    @foreach ([
                        'School' => $batch->membership->application?->company_name ?? '—',
                        'School Club number' => $batch->membership->membership_number,
                        'Submitted by' => $batch->submittedBy?->name.' ('.$batch->submittedBy?->email.')',
                        'Submitted' => $batch->submitted_at->format('j F Y, H:i').' UTC',
                        'Students' => $batch->students_count,
                    ] as $label => $value)
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $label }}</dt>
                            <dd class="mt-1 break-words">{{ $value }}</dd>
                        </div>
                    @endforeach
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Status</dt>
                        <dd class="mt-1"><x-admin.status-badge :status="$batch->status" /></dd>
                    </div>
                </dl>

                @if ($batch->studentList)
                    <p class="mt-4 text-sm">
                        Principal-signed student list:
                        <a href="{{ route('admin.documents.show', $batch->studentList) }}" class="text-primary underline underline-offset-2 hover:text-secondary">{{ $batch->studentList->original_filename }}</a>
                    </p>
                @endif

                @if ($batch->request_message && $batch->status === SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED)
                    <p class="mt-4 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"><span class="font-semibold">Requested from the school:</span> {{ $batch->request_message }}</p>
                @endif
                @if ($batch->decision_note)
                    <p class="mt-2 text-sm text-muted-foreground"><span class="font-semibold">Internal note:</span> {{ $batch->decision_note }}</p>
                @endif
            </section>

            <section class="ui-card overflow-x-auto p-6" aria-labelledby="students-heading">
                <h2 id="students-heading" class="font-display text-lg font-bold text-primary">Students</h2>
                <table class="mt-4 w-full min-w-[30rem] text-left text-sm">
                    <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">#</th>
                            <th scope="col" class="py-2 pr-4 font-medium">Full name</th>
                            <th scope="col" class="py-2 pr-4 font-medium">Email</th>
                            <th scope="col" class="py-2 font-medium">Membership number</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($batch->entries as $entry)
                            <tr>
                                <td class="py-2 pr-4">{{ $loop->iteration }}</td>
                                <td class="py-2 pr-4">{{ $entry->full_name }}</td>
                                <td class="py-2 pr-4 break-all">{{ $entry->email }}</td>
                                <td class="py-2 font-mono text-xs">{{ $entry->membership?->membership_number ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        </div>

        <section class="ui-card h-fit p-6" aria-labelledby="decision-heading">
            <h2 id="decision-heading" class="font-display text-lg font-bold text-primary">Decision</h2>

            @if ($batch->status === SchoolClubStudentBatch::STATUS_SUBMITTED)
                <form method="POST" action="{{ route('admin.school-club-batches.review', $batch) }}" class="mt-4 space-y-4">
                    @csrf

                    @error('decision')
                        <p class="rounded-lg border border-destructive/50 px-3 py-2 text-xs text-destructive" role="alert">{{ $message }}</p>
                    @enderror

                    <fieldset class="space-y-2">
                        <legend class="sr-only">Decision</legend>
                        @foreach ([
                            SchoolClubStudentBatch::STATUS_APPROVED => 'Accept',
                            SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED => 'Request More Details',
                            SchoolClubStudentBatch::STATUS_REJECTED => 'Decline',
                        ] as $value => $label)
                            <label class="flex cursor-pointer items-center gap-2 text-sm">
                                <input type="radio" name="decision" value="{{ $value }}" @checked(old('decision') === $value) required>
                                {{ $label }}
                            </label>
                        @endforeach
                    </fieldset>

                    <div class="space-y-1.5">
                        <label for="request_message" class="block text-sm font-medium leading-none">Message to the school (required for Request More Details)</label>
                        <textarea id="request_message" name="request_message" rows="3" maxlength="2000" @error('request_message') aria-invalid="true" @enderror class="field-control">{{ old('request_message') }}</textarea>
                        @error('request_message')
                            <p class="text-xs text-destructive">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-1.5">
                        <label for="note" class="block text-sm font-medium leading-none">Admin note (internal)</label>
                        <textarea id="note" name="note" rows="3" maxlength="2000" class="field-control">{{ old('note') }}</textarea>
                    </div>

                    <div class="space-y-1.5">
                        <label class="flex items-start gap-2 text-sm">
                            <input type="checkbox" name="list_reviewed" value="1" class="mt-0.5" @checked(old('list_reviewed'))>
                            <span>I have checked the principal-signed student list (required to accept). Accepting creates each student's membership and sends their setup link.</span>
                        </label>
                        @error('list_reviewed')
                            <p class="text-xs text-destructive">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-lg btn-gradient w-full">Record decision</button>
                </form>
            @else
                <p class="mt-3 text-sm text-muted-foreground">{{ $batch->status === SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED ? 'Waiting for the school to resubmit.' : 'This batch has been decided.' }}</p>
            @endif
        </section>
    </div>
</x-layouts.admin>
