{{-- Flash status, publish-validation errors and "not saved" errors, shown on the index and view pages. --}}
@if (session('status'))
    <p class="mt-4 rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-3 text-sm text-primary" role="status">{{ session('status') }}</p>
@endif

@if ($errors->any())
    <div class="mt-4 rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
        <p class="font-semibold">{{ $errors->has('job') ? 'The change was not saved:' : 'This job could not be published:' }}</p>
        <ul class="mt-1 list-disc pl-5">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
