<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Admin · Aṣẹ</title>
    <style>
        :root { --bg: #0b1020; --card: #131a2e; --line: #26304d; --text: #e6ebf5; --muted: #8b96b0; --accent: #3f8cff; --err: #ff6b6b; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { width: 100%; max-width: 400px; background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 34px 30px; margin: 16px; }
        h1 { font-size: 20px; margin-bottom: 4px; }
        p.sub { color: var(--muted); font-size: 13px; margin-bottom: 24px; }
        label { display: block; font-size: 12px; color: var(--muted); margin: 14px 0 6px; letter-spacing: .04em; text-transform: uppercase; }
        input { width: 100%; padding: 11px 12px; border-radius: 9px; border: 1px solid var(--line); background: #0d1426; color: var(--text); font-size: 14px; }
        input:focus { outline: none; border-color: var(--accent); }
        button { width: 100%; margin-top: 22px; padding: 12px; border: 0; border-radius: 9px; background: var(--accent); color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; }
        button:hover { filter: brightness(1.1); }
        .err { color: var(--err); font-size: 13px; margin-top: 4px; }
        .errors { background: rgba(255,107,107,.1); border: 1px solid rgba(255,107,107,.4); padding: 10px 12px; border-radius: 9px; font-size: 13px; color: var(--err); margin-bottom: 10px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Aṣẹ Admin</h1>
    <p class="sub">Sign in with an administrator account.</p>

    @if ($errors->any())
        <div class="errors">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.login.attempt') }}">
        @csrf
        <label for="phone">Phone number</label>
        <input id="phone" name="phone" type="tel" placeholder="0803 123 4567" value="{{ old('phone') }}" required autofocus>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" placeholder="••••••••" required>

        <button type="submit">Sign in</button>
    </form>
</div>
</body>
</html>
