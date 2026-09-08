{{-- Shared shell for every generated PDF: receipts, report cards, certificates. --}}
<!DOCTYPE html>
<html lang="{{ $school->locale ?? 'fr' }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'SchoolFlow')</title>
    <style>
        @page { margin: 24mm 18mm; }
        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 10.5pt;
            color: #111827;
            line-height: 1.45;
        }
        .header { border-bottom: 2px solid #1d4ed8; padding-bottom: 12px; margin-bottom: 20px; }
        .header table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: middle; }
        .logo { width: 68px; height: auto; }
        .school-name { font-size: 15pt; font-weight: bold; color: #1e3a8a; margin: 0; }
        .school-meta { font-size: 8.5pt; color: #4b5563; margin: 2px 0 0; }
        .doc-title { font-size: 13pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.06em; margin: 0 0 2px; }
        .doc-number { font-size: 9pt; color: #4b5563; }
        h2 { font-size: 10pt; text-transform: uppercase; letter-spacing: 0.05em; color: #374151;
             border-bottom: 1px solid #e5e7eb; padding-bottom: 4px; margin: 18px 0 8px; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        table.data th {
            background: #f3f4f6; text-align: left; font-size: 8.5pt; text-transform: uppercase;
            letter-spacing: 0.04em; color: #374151; padding: 6px 8px; border-bottom: 1px solid #d1d5db;
        }
        table.data td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; }
        table.data tr.total td { font-weight: bold; background: #f9fafb; border-top: 1.5px solid #d1d5db; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #6b7280; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 8.5pt; font-weight: bold; }
        .badge-paid { background: #d1fae5; color: #065f46; }
        .badge-due  { background: #fee2e2; color: #991b1b; }
        .signature { margin-top: 42px; width: 100%; }
        .signature td { width: 50%; vertical-align: bottom; padding-top: 34px; font-size: 9pt; }
        .signature .line { border-top: 1px solid #9ca3af; padding-top: 4px; width: 62%; }
        .footer { position: fixed; bottom: -16mm; left: 0; right: 0;
                  font-size: 7.5pt; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <table>
            <tr>
                <td style="width: 76px;">
                    @if (! empty($logo))
                        <img src="{{ $logo }}" class="logo" alt="">
                    @endif
                </td>
                <td>
                    <p class="school-name">{{ $school->name }}</p>
                    <p class="school-meta">
                        {{ collect([$school->address_line1, $school->city, $school->country])->filter()->join(' · ') }}
                        @if ($school->phone) · {{ $school->phone }} @endif
                        @if ($school->email) · {{ $school->email }} @endif
                    </p>
                </td>
                <td style="text-align: right;">
                    <p class="doc-title">@yield('document-title')</p>
                    <p class="doc-number">@yield('document-number')</p>
                </td>
            </tr>
        </table>
    </div>

    @yield('content')

    <div class="footer">
        {{ $school->name }} — @yield('document-title') @yield('document-number')
        · {{ __('pdf.generated_on', ['date' => now()->format('d/m/Y H:i')]) }}
    </div>
</body>
</html>
