<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Horizon')</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #1a1a18;
            color: #e9e4d6;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            padding: 2rem;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: #1d1c1a;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-top: 2px solid #c99d3d;
            border-radius: 8px;
            padding: 28px 32px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        h1 {
            margin: 0 0 8px;
            font-size: 20px;
            color: #c99d3d;
            letter-spacing: 0.02em;
        }
        .hint {
            margin: 0 0 22px;
            font-size: 12px;
            line-height: 1.6;
            color: #b6b09e;
        }
        .field { margin-bottom: 14px; }
        label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #b6b09e;
            margin-bottom: 6px;
        }
        input[type=text], input[type=password] {
            width: 100%;
            padding: 9px 11px;
            background: #14130f;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            color: #f3eddc;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 13px;
        }
        input:focus {
            outline: none;
            border-color: #c99d3d;
        }
        button {
            width: 100%;
            margin-top: 10px;
            padding: 10px 14px;
            background: #c99d3d;
            color: #1a1408;
            border: 0;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            cursor: pointer;
        }
        button:hover { background: #d6ab4d; }
        .errors {
            margin: 0 0 14px;
            padding: 10px 12px;
            background: rgba(209, 90, 74, 0.12);
            border-left: 2px solid #d15a4a;
            border-radius: 0 4px 4px 0;
            font-size: 12px;
            color: #e88;
        }
        .errors ul { margin: 0; padding-left: 18px; }
        .note {
            margin: 18px 0 0;
            padding: 12px 14px;
            background: #14130f;
            border-left: 2px solid rgba(255, 255, 255, 0.15);
            border-radius: 0 4px 4px 0;
            font-size: 11px;
            line-height: 1.6;
            color: #b6b09e;
        }
        .note code {
            display: block;
            margin-top: 4px;
            padding: 4px 8px;
            background: #0a0a08;
            border-radius: 3px;
            font-size: 11px;
            color: #f3eddc;
            white-space: pre-wrap;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="card">
        @yield('content')
    </div>
</body>
</html>
