<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: sans-serif; font-size: 12px; color: #1e293b; }
    h1 { font-size: 18px; margin-bottom: 2px; }
    p.meta { color: #64748b; margin-top: 0; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; margin-top: 14px; }
    th, td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; }
    th { background: #f1f5f9; text-transform: uppercase; font-size: 9px; letter-spacing: .05em; }
    tr:nth-child(even) { background: #f8fafc; }
</style>
</head>
<body>
    <h1>{{ ucfirst($type) }} Report</h1>
    <p class="meta">Generated {{ $generatedAt->format('M j, Y g:i A') }}</p>

    <table>
        <thead>
            <tr>
                @foreach($headings as $h)
                    <th>{{ $h }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headings) }}">No records in this range.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>