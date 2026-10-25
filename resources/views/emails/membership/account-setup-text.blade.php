Aviation Club

Hello {!! str_replace(["\r", "\n"], ' ', $name) !!},

@if ($editorInvitation ?? false)
You have been invited to join Aviation Club as an editor. To finish setting up your account, please create your password using the link below.
@else
Your Aviation Club membership is now active. To finish setting up your account, please create your password using the link below.
@endif

{!! $url !!}

This link can be used once only and expires on {!! $expiresAt !!}. After you have created your password, or once it has expired, it will stop working.

After creating your password, sign in to the member portal at {!! route('login') !!} using your registered email address (the address this email was sent to) as your username.

If you did not expect this email, you can ignore it. Never share this link with anyone.

Aviation Club
