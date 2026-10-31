@use('App\Models\SchoolClubStudentBatch')
@use('App\Support\SchoolClub\StudentListTemplate')

{{--
    Manage Student Members — main School Club membership only (the controller
    returns 403 for anyone else). Adding students: download the template,
    upload the completed Excel, print the generated list for the principal to
    sign, then upload the signed PDF. Students become members only after
    Aviation Club approves a submitted list.
--}}
<x-layouts.member :title="'Manage Student Members'">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Manage Student Members</h1>
    <p class="mt-1 text-sm text-muted-foreground">School Club {{ $membership->membership_number }}. Student membership is free — no joining or renewal fees.</p>

    @if ($errors->has('students_file') || $errors->has('principal_list'))
        <div class="mt-6 rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive" role="alert">
            <p class="font-semibold">The student list could not be accepted:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ([...$errors->get('students_file'), ...$errors->get('principal_list')] as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $awaiting = $batches->where('status', SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST);
        $sent = $batches->where('status', '!=', SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST);
    @endphp

    <section class="ui-card mt-6 p-6" aria-labelledby="submit-heading">
        <h2 id="submit-heading" class="font-display text-lg font-bold text-primary">Add students</h2>

        @if ($canSubmit)
            <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
                <li>Download the student template and fill in one student per row: {{ implode(' | ', StudentListTemplate::HEADERS) }} (date of birth as DD/MM/YYYY). Each student needs their own unique email address. You can replace or delete the John Perera example row.</li>
                <li>Upload the completed template (up to 999 students, 2 MB).</li>
                <li>Download the printable student list, and have the principal sign it and stamp the school seal.</li>
                <li>Upload the scanned, signed list as a PDF. Aviation Club then reviews it before any student account is created.</li>
            </ol>

            <div class="mt-4 flex flex-wrap items-end gap-4">
                <a href="{{ route('member.school-club.students.template') }}" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Download template (Excel)</a>

                <form method="POST" action="{{ route('member.school-club.students.store') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div class="space-y-1.5">
                        <label for="students_file" class="block text-sm font-medium leading-none">Completed template (Excel)</label>
                        <input id="students_file" name="students_file" type="file" required accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" class="field-control">
                    </div>
                    <button type="submit" class="btn btn-lg btn-gradient">Upload student list</button>
                </form>
            </div>
            @if ($awaiting->whereNull('request_message')->isNotEmpty())
                <p class="mt-3 text-xs text-muted-foreground">Uploading a new template replaces the list below that is still waiting for its signed PDF.</p>
            @endif
        @else
            <p class="mt-2 text-sm text-muted-foreground">Your School Club membership is not currently active. Renew it free of charge from your membership page before adding students.</p>
        @endif
    </section>

    @foreach ($awaiting as $batch)
        <section class="ui-card mt-6 p-6" aria-labelledby="awaiting-{{ $batch->public_id }}" data-awaiting-signed-list>
            <h2 id="awaiting-{{ $batch->public_id }}" class="font-display text-lg font-bold text-primary">List waiting for the principal's signature</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ $batch->students_count }} student(s)
                @if ($batch->spreadsheet)
                    &middot; <a href="{{ route('member.documents.show', $batch->spreadsheet) }}" class="text-primary underline underline-offset-2">Uploaded Excel</a>
                @endif
            </p>
            @if ($batch->request_message)
                <p class="mt-3 rounded-lg border border-border bg-muted/40 px-3 py-2 text-sm"><span class="font-semibold">Aviation Club needs:</span> {{ $batch->request_message }}</p>
            @endif

            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div class="space-y-2">
                    <p class="text-sm font-medium">Step 3 — print and sign</p>
                    <a href="{{ route('member.school-club.students.printable', $batch) }}" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Download printable list (PDF)</a>
                </div>
                <form method="POST" action="{{ route('member.school-club.students.signed', $batch) }}" enctype="multipart/form-data" class="space-y-2">
                    @csrf
                    <label for="principal_list_{{ $batch->public_id }}" class="block text-sm font-medium">Step 4 — upload the signed list (scanned PDF)</label>
                    <input id="principal_list_{{ $batch->public_id }}" name="principal_list" type="file" required accept=".pdf,application/pdf" class="field-control">
                    <button type="submit" class="btn btn-lg btn-gradient">Submit for review</button>
                </form>
            </div>

            <form method="POST" action="{{ route('member.school-club.students.resubmit', $batch) }}" enctype="multipart/form-data" class="mt-4 flex flex-wrap items-end gap-3 border-t border-border pt-4">
                @csrf
                <div class="space-y-1.5">
                    <label for="students_file_{{ $batch->public_id }}" class="block text-xs font-medium">Need to change the list? Upload a corrected template</label>
                    <input id="students_file_{{ $batch->public_id }}" name="students_file" type="file" required accept=".xlsx,.csv" class="field-control">
                </div>
                <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Replace list</button>
            </form>
        </section>
    @endforeach

    <section class="ui-card mt-6 overflow-x-auto p-6" aria-labelledby="batches-heading">
        <h2 id="batches-heading" class="font-display text-lg font-bold text-primary">Submitted lists</h2>

        @if ($sent->isEmpty())
            <p class="mt-2 text-sm text-muted-foreground">No student lists submitted yet.</p>
        @else
            <ul class="mt-4 divide-y divide-border">
                @foreach ($sent as $batch)
                    <li class="py-3 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>{{ $batch->submitted_at->format('j F Y') }} &middot; {{ $batch->students_count }} student(s)
                                @if ($batch->spreadsheet)
                                    &middot; <a href="{{ route('member.documents.show', $batch->spreadsheet) }}" class="text-primary underline underline-offset-2">Excel</a>
                                @endif
                                @if ($batch->studentList)
                                    &middot; <a href="{{ route('member.documents.show', $batch->studentList) }}" class="text-primary underline underline-offset-2">Principal-signed list</a>
                                @endif
                            </span>
                            <span class="rounded-md border border-border px-2.5 py-0.5 text-xs font-semibold">{{ SchoolClubStudentBatch::STATUS_LABELS[$batch->status] }}</span>
                        </div>

                        @if ($batch->status === SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED)
                            <p class="mt-2 rounded-lg border border-border bg-muted/40 px-3 py-2"><span class="font-semibold">Aviation Club needs:</span> {{ $batch->request_message }}</p>
                            <form method="POST" action="{{ route('member.school-club.students.resubmit', $batch) }}" enctype="multipart/form-data" class="mt-3 flex flex-wrap items-end gap-3">
                                @csrf
                                <div class="space-y-1.5">
                                    <label for="students_file_{{ $batch->public_id }}" class="block text-xs font-medium">Corrected template (Excel)</label>
                                    <input id="students_file_{{ $batch->public_id }}" name="students_file" type="file" required accept=".xlsx,.csv" class="field-control">
                                </div>
                                <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Upload corrected list</button>
                            </form>
                            <p class="mt-2 text-xs text-muted-foreground">You will then print the corrected list for the principal to sign and upload the signed PDF again.</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="ui-card mt-6 overflow-x-auto p-6" aria-labelledby="students-heading">
        <h2 id="students-heading" class="font-display text-lg font-bold text-primary">Student members</h2>

        @if ($students->isEmpty())
            <p class="mt-2 text-sm text-muted-foreground">No student members yet.</p>
        @else
            <table class="mt-4 w-full min-w-[30rem] text-left text-sm">
                <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th scope="col" class="py-2 pr-4 font-medium">Membership number</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Name</th>
                        <th scope="col" class="py-2 font-medium">Account</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($students as $student)
                        <tr>
                            <td class="py-2 pr-4 font-mono text-xs">{{ $student->membership_number }}</td>
                            <td class="py-2 pr-4">{{ $student->user?->name }}</td>
                            <td class="py-2">{{ $student->user?->status === 'active' ? 'Set up' : 'Awaiting setup' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</x-layouts.member>
