@extends('pdf.layout')

@section('title', __('pdf.certificate'))
@section('document-title', $title)
@section('document-number', __('pdf.certificate_number', ['number' => $certificate->number]))

@section('content')
    <p style="margin-top: 28px; font-size: 11.5pt; line-height: 1.9;">
        {!! $body !!}
    </p>

    <p style="margin-top: 34px; font-size: 10pt;" class="muted">
        {{ __('pdf.issued_on', ['date' => $certificate->issued_at->format('d/m/Y')]) }}
    </p>

    <table class="signature">
        <tr>
            <td>
                <div class="line">{{ __('pdf.principal') }}</div>
            </td>
            <td style="text-align: right; vertical-align: bottom;">
                {{-- The verification code is the only thing the public route
                     accepts, and it reveals an attestation, not a record. --}}
                <p style="font-size: 8pt; color: #6b7280; margin: 0;">
                    {{ __('pdf.verification_notice', [
                        'url' => $verificationUrl,
                        'code' => $certificate->verification_code,
                    ]) }}
                </p>
            </td>
        </tr>
    </table>
@endsection
