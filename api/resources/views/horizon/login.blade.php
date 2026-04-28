@extends('horizon._layout')

@section('title', 'Horizon — sign in')

@section('content')
    <h1>Horizon dashboard</h1>
    <p class="hint">
        Enter the password set during first-time setup on this environment.
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

    <form method="POST" action="/horizon-login" autocomplete="off">
        @csrf
        @if (request()->filled('next'))
            <input type="hidden" name="next" value="{{ request()->query('next') }}">
        @endif
        <div class="field">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required autofocus>
        </div>
        <button type="submit">Sign in</button>
    </form>
@endsection
