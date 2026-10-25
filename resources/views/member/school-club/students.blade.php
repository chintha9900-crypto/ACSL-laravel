@use('App\Models\SchoolClubStudentBatch')

{{--
    Manage Student Members — main School Club membership only (the controller
    returns 403 for anyone else). Students become members only after Aviation
    Club approves a submitted list.
--}}
<x-layouts.member :title="'Manage Student Members'">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Manage Student Members</h1>
    <p class="mt-1 text-sm text-muted-foreground">School Club {{ $membership->membership_number }}. Student membership is free — no joining or renewal fees.</p>

    @if ($errors->has('students_file') || $errors->has('principal_list'))
        <div class="mt-6 rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive" role="alert">
            <p class="font-semibold">The student list could not be submitted:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ([...$errors->get('students_file'), ...$errors->get('principal_list')] as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section class="ui-card mt-6 p-6" aria-labelledby="submit-heading">
        <h2 id="submit-heading" class="font-display text-lg font-bold text-primary">Add students</h2>

        @if ($canSubmit)
            <p class="mt-2 text-sm text-muted-foreground">
                Upload a CSV or Excel (.xlsx) file whose first row has the headings <code>full_name</code> and <code>email</code>, one student per row, each with their own unique email address (up to 999 students, 2 MB).
                Also attach the student list signed by the school principal, as a PDF. Aviation Club reviews every list before any student account is created.
            </p>

            <form method="POST" action="{{ route('member.school-club.students.store') }}" enctype="multipart/form-data" class="mt-4 grid gap-4 md:grid-cols-2">
                @csrf
                <div class="space-y-1.5">
                    <label for="students_file" class="block text-sm font-medium leading-none">Student list (CSV or Excel)</label>
                    <input id="students_file" name="students_file" type="file" required accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="field-control">
                </div>
                <div class="space-y-1.5">
                    <label for="principal_list" class="block text-sm font-medium leading-none">Principal-signed student list (PDF)</label>
                    <input id="principal_list" name="principal_list" type="file" required accept=".pdf,application/pdf" class="field-control">
                </div>
                <div class="md:col-span-2">
                    <button type="submit" class="btn btn-lg btn-gradient">Submit for review</button>
                </div>
            </form>
        @else
            <p class="mt-2 text-sm text-muted-foreground">Your School Club membership is not currently active. Renew it free of charge from your membership page before adding students.</p>
        @endif
    </section>

    <section class="ui-card mt-6 overflow-x-auto p-6" aria-labelledby="batches-heading">
        <h2 id="batches-heading" class="font-display text-lg font-bold text-primary">Submitted lists</h2>

        @if ($batches->isEmpty())
            <p class="mt-2 text-sm text-muted-foreground">No student lists submitted yet.</p>
        @else
            <ul class="mt-4 divide-y divide-border">
                @foreach ($batches as $batch)
                    <li class="py-3 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>{{ $batch->submitted_at->format('j F Y') }} &middot; {{ $batch->students_count }} student(s)
                                @if ($batch->studentList)
                                    &middot; <a href="{{ route('member.documents.show', $batch->studentList) }}" class="text-primary underline underline-offset-2">Principal-signed list</a>
                                @endif
                            </span>
                            <span class="rounded-md border border-border px-2.5 py-0.5 text-xs font-semibold">{{ SchoolClubStudentBatch::STATUS_LABELS[$batch->status] }}</span>
                        </div>

                        @if ($batch->status === SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED)
                            <p class="mt-2 rounded-lg border border-border bg-muted/40 px-3 py-2"><span class="font-semibold">Aviation Club needs:</span> {{ $batch->request_message }}</p>
                            <form method="POST" action="{{ route('member.school-club.students.resubmit', $batch) }}" enctype="multipart/form-data" class="mt-3 grid gap-3 md:grid-cols-2">
                                @csrf
                                <div class="space-y-1.5">
                                    <label for="students_file_{{ $batch->public_id }}" class="block text-xs font-medium">Corrected student list (CSV or Excel)</label>
                                    <input id="students_file_{{ $batch->public_id }}" name="students_file" type="file" required accept=".csv,.xlsx" class="field-control">
                                </div>
                                <div class="space-y-1.5">
                                    <label for="principal_list_{{ $batch->public_id }}" class="block text-xs font-medium">Principal-signed student list (PDF)</label>
                                    <input id="principal_list_{{ $batch->public_id }}" name="principal_list" type="file" required accept=".pdf,application/pdf" class="field-control">
                                </div>
                                <div class="md:col-span-2">
                                    <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Resubmit</button>
                                </div>
                            </form>
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
