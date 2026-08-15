<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} — {{ $worldName }}</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; margin: 0; }
        html, body { height: 100%; }
        body {
            font-family: Georgia, 'Times New Roman', serif;
            background: #14161b;
            color: #e6e2d8;
            padding: 14px;
        }
        a.card {
            display: flex;
            flex-direction: column;
            gap: 8px;
            height: 100%;
            padding: 16px 18px;
            border: 1px solid #262a33;
            border-radius: 10px;
            background: #181b21;
            text-decoration: none;
            color: inherit;
            transition: border-color .15s ease;
        }
        a.card:hover { border-color: {{ $accent }}; }
        .kind {
            font-family: ui-monospace, 'Courier New', monospace;
            font-size: 10px;
            letter-spacing: .16em;
            text-transform: uppercase;
            color: {{ $accent }};
        }
        h1 { font-size: 20px; font-weight: 600; line-height: 1.2; color: #f3efe6; }
        p.summary {
            font-size: 14px;
            line-height: 1.55;
            color: #9aa0ab;
            display: -webkit-box;
            -webkit-line-clamp: 4;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .foot {
            margin-top: auto;
            font-family: ui-monospace, 'Courier New', monospace;
            font-size: 11px;
            letter-spacing: .06em;
            color: #6b7180;
        }
        .foot b { color: {{ $accent }}; font-weight: normal; }
    </style>
</head>
<body>
    <a class="card" href="{{ $url }}" target="_blank" rel="noopener">
        <span class="kind">{{ $kindLabel }}</span>
        <h1>{{ $title }}</h1>
        @if ($summary !== '')
            <p class="summary">{{ $summary }}</p>
        @endif
        <span class="foot">Read on <b>{{ $worldName }}</b> &rarr;</span>
    </a>
</body>
</html>
