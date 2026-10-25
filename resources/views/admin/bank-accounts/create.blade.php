<x-layouts.admin title="Add bank account">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Add receiving bank account</h1>
    <p class="mt-1 text-sm text-muted-foreground">Details cannot be edited after saving. To change an account, add a new one and make it active.</p>

    @if ($errors->any())
        <div class="mt-4 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">Please correct the highlighted fields and try again.</div>
    @endif

    <form method="POST" action="{{ route('admin.bank-accounts.store') }}" class="ui-card mt-6 max-w-xl space-y-4 p-6 md:p-7">
        @csrf
        <x-form.input name="label" label="Label" maxlength="100" hint="For admins only, e.g. Main LKR account." />
        <x-form.input name="bank_name" label="Bank" maxlength="150" />
        <x-form.input name="account_name" label="Account name" maxlength="150" />
        <x-form.input name="account_number" label="Account number" maxlength="50" />
        <x-form.input name="branch" label="Branch" :required="false" maxlength="150" />
        <x-form.input name="swift_bic" label="SWIFT / BIC" :required="false" maxlength="11" />
        <x-form.input name="iban" label="IBAN" :required="false" maxlength="34" />
        <input type="hidden" name="currency" value="LKR">
        <p class="text-sm">Currency: <strong>LKR</strong></p>
        <x-form.input name="instructions" label="Instructions shown to customers" type="textarea" rows="3" :required="false" maxlength="1000" />
        <label class="flex items-start gap-2 text-sm">
            <input type="hidden" name="activate" value="0">
            <input type="checkbox" name="activate" value="1" class="mt-0.5" @checked(old('activate'))>
            <span>Make this the active LKR account now (the current active account is deactivated).</span>
        </label>
        <div class="flex gap-3">
            <button type="submit" class="btn btn-lg btn-brand">Save account</button>
            <a href="{{ route('admin.bank-accounts.index') }}" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent">Cancel</a>
        </div>
    </form>
</x-layouts.admin>
