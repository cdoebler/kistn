@extends('mail.layout')

@section('content')
    <h1 style="margin:0 0 8px; font-size:20px; color:#111827;">{{ __('Package now publicly listed') }}</h1>
    {{-- Raw {!! !!} output is safe here only because $project->name is escaped via e()
         before being interpolated into the :project placeholder — the whole sentence is
         translated as one unit (instead of split around the link) to keep German word order
         correct, so the resulting HTML has to be embedded unescaped. --}}
    <p style="margin:0 0 20px; color:#4b5563; font-size:14px; line-height:1.6;">
        {!! __('A package in :project was previously marked private but is now found in the WPScan vulnerability database. It will be included in vulnerability scans from the next push onwards.', [
            'project' => '<a href="'.route('projects.packages', $project).'" style="color:#4338ca; text-decoration:none; font-weight:600;">'.e($project->name).'</a>',
        ]) !!}
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb; border-radius:8px;">
        <tr>
            <td style="padding:16px 20px;">
                <div style="margin-bottom:8px;">
                    @include('mail.partials.ecosystem-badge', ['ecosystem' => $ecosystem->value, 'url' => route('projects.packages', ['project' => $project, 'ecosystem' => $ecosystem->value])])
                </div>
                <div style="font-size:15px; font-weight:600; color:#111827; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $package->name }}</div>
            </td>
        </tr>
    </table>
@endsection
