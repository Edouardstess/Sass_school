@extends('pdf.layout')

@section('title', __('pdf.report_card_title', ['student' => $student->full_name]))
@section('document-title', __('pdf.report_card'))
@section('document-number', $period->name)

@section('content')
    <table class="data" style="margin-bottom: 14px;">
        <tr>
            <td style="width: 18%;" class="muted">{{ __('pdf.student') }}</td>
            <td style="width: 32%;"><strong>{{ $student->full_name }}</strong></td>
            <td style="width: 18%;" class="muted">{{ __('pdf.class') }}</td>
            <td>{{ $class->name }}{{ $class->level ? ' — '.$class->level->name : '' }}</td>
        </tr>
        <tr>
            <td class="muted">{{ __('pdf.reference') }}</td>
            <td>{{ $student->matricule }}</td>
            <td class="muted">{{ __('pdf.academic_year') }}</td>
            <td>{{ $year?->name ?? '—' }}</td>
        </tr>
    </table>

    <h2>{{ __('pdf.period') }} — {{ $period->name }}</h2>
    <table class="data">
        <thead>
            <tr>
                <th>{{ __('pdf.subject') }}</th>
                <th class="num">{{ __('pdf.coefficient') }}</th>
                <th class="num">{{ __('pdf.average') }}</th>
                <th class="num">{{ __('pdf.class_average') }}</th>
                <th class="num">{{ __('pdf.min') }}</th>
                <th class="num">{{ __('pdf.max') }}</th>
                <th class="num">{{ __('pdf.rank') }}</th>
                <th>{{ __('pdf.appreciation') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>
                        {{ $line->subject_name }}
                        @if ($line->teacher_name)
                            <span class="muted" style="font-size: 8pt;"><br>{{ $line->teacher_name }}</span>
                        @endif
                    </td>
                    <td class="num">{{ rtrim(rtrim(number_format((float) $line->coefficient, 2, ',', ' '), '0'), ',') }}</td>
                    {{-- An unmarked subject shows an em dash, never a zero. --}}
                    <td class="num"><strong>{{ $line->average !== null ? number_format((float) $line->average, 2, ',', ' ') : '—' }}</strong></td>
                    <td class="num muted">{{ $line->class_average !== null ? number_format((float) $line->class_average, 2, ',', ' ') : '—' }}</td>
                    <td class="num muted">{{ $line->min_score !== null ? number_format((float) $line->min_score, 2, ',', ' ') : '—' }}</td>
                    <td class="num muted">{{ $line->max_score !== null ? number_format((float) $line->max_score, 2, ',', ' ') : '—' }}</td>
                    <td class="num">{{ $line->rank ?? '—' }}</td>
                    <td style="font-size: 9pt;">{{ $line->appreciation ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="width: 100%; margin-top: 16px;">
        <tr>
            <td style="width: 58%; vertical-align: top; padding-right: 14px;">
                <h2 style="margin-top: 0;">{{ __('pdf.general_average') }}</h2>
                <table class="data">
                    <tr class="total">
                        <td>{{ __('pdf.general_average') }}</td>
                        <td class="num" style="font-size: 14pt;">
                            {{ $card->average !== null ? number_format((float) $card->average, 2, ',', ' ') : '—' }}
                        </td>
                    </tr>
                    <tr>
                        <td class="muted">{{ __('pdf.general_rank') }}</td>
                        <td class="num">
                            {{ $card->rank ?? '—' }}
                            <span class="muted">{{ __('pdf.out_of', ['count' => $card->class_size]) }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td class="muted">{{ __('pdf.class_average') }}</td>
                        <td class="num">{{ $card->class_average !== null ? number_format((float) $card->class_average, 2, ',', ' ') : '—' }}</td>
                    </tr>
                </table>
            </td>
            <td style="width: 42%; vertical-align: top;">
                <h2 style="margin-top: 0;">{{ __('pdf.attendance_summary') }}</h2>
                <table class="data">
                    <tr>
                        <td class="muted">{{ __('pdf.absences') }}</td>
                        <td class="num">{{ $card->absences_count }}</td>
                    </tr>
                    <tr>
                        <td class="muted">{{ __('pdf.lates') }}</td>
                        <td class="num">{{ $card->late_count }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    @if ($card->remarks)
        <h2>{{ __('pdf.remarks') }}</h2>
        <p style="font-size: 10pt;">{{ $card->remarks }}</p>
    @endif

    <table class="signature">
        <tr>
            <td><div class="line">{{ __('pdf.principal') }}</div></td>
            <td style="text-align: right;"><div class="line" style="margin-left: auto;">{{ __('pdf.stamp') }}</div></td>
        </tr>
    </table>
@endsection
