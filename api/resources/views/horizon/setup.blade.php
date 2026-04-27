@extends('horizon._layout')

@section('title', 'Horizon — first-time setup')

@section('content')
    <h1>Horizon — first-time setup</h1>
    <p class="hint">
        Choose a password for the Horizon dashboard on this environment.
        It applies to anyone who knows it — pick something only you and
        your operators know.
    </p>

    @if ($errors->any())
        <div class="errors">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="/horizon-setup" autocomplete="off">
        @csrf
        @if (request()->filled('next'))
            <input type="hidden" name="next" value="{{ request()->query('next') }}">
        @endif
        <div class="field">
            <label for="token">Setup token</label>
            <input id="token" type="text" name="token" required autofocus
                   value="{{ old('token') }}">
        </div>
        <div class="field">
            <label for="password">New password (min 12 chars)</label>
            <input id="password" type="password" name="password" required minlength="12">
        </div>
        <div class="field">
            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" type="password"
                   name="password_confirmation" required minlength="12">
        </div>
        <button type="submit">Create credentials</button>
    </form>

    <div class="note">
        The setup token has been logged once. Retrieve it with:
        <code>docker compose -p &lt;project&gt; logs api | grep HORIZON_SETUP_TOKEN</code>
        Token expires after 24h and is single-use. Lost it? Run
        <code>docker compose -p &lt;project&gt; exec api php artisan horizon:reset-credentials</code>
        to clear it and reload this page to mint a new one.
    </div>
@endsection
