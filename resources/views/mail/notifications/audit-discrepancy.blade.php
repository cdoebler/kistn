@extends('mail.layout')

@section('content')
    <h1 style="margin:0 0 8px; font-size:20px; color:#111827;">{{ __('Audit discrepancy') }}</h1>
    {{-- Raw {!! !!} output is safe here only because $project->name is escaped via e()
         before being interpolated into the :project placeholder — the whole sentence is
         translated as one unit (instead of split around the link) to keep German word order
         correct, so the resulting HTML has to be embedded unescaped. --}}
    <p style="margin:0 0 4px; color:#4b5563; font-size:14px; line-height:1.6;">
        {!! __('Server-side audit findings differ from client-reported findings for :project.', [
            'project' => '<a href="'.route('projects.packages', $project).'" style="color:#4338ca; text-decoration:none; font-weight:600;">'.e($project->name).'</a>',
        ]) !!}
    </p>
    <div style="margin-bottom:20px;">
        @include('mail.partials.ecosystem-badge', ['ecosystem' => $ecosystem->value, 'url' => route('projects.packages', ['project' => $project, 'ecosystem' => $ecosystem->value])])
    </div>

    @if ($onlyInServer !== [])
        <div style="margin-bottom:20px;">
            <div style="font-size:13px; font-weight:600; color:#111827; margin-bottom:8px;">{{ __('Found by server, not reported by client') }}</div>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #fecaca; border-radius:8px; background-color:#fef2f2;">
                <tr>
                    <td style="padding:12px 16px;">
                        @foreach ($onlyInServer as $advisoryId)
                            <div style="font-size:13px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; color:#991b1b; padding:2px 0;">{{ $advisoryId }}</div>
                        @endforeach
                    </td>
                </tr>
            </table>
        </div>
    @endif

    @if ($onlyInClient !== [])
        <div>
            <div style="font-size:13px; font-weight:600; color:#111827; margin-bottom:8px;">{{ __('Reported by client, not found by server') }}</div>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #fde68a; border-radius:8px; background-color:#fffbeb;">
                <tr>
                    <td style="padding:12px 16px;">
                        @foreach ($onlyInClient as $advisoryId)
                            <div style="font-size:13px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; color:#92400e; padding:2px 0;">{{ $advisoryId }}</div>
                        @endforeach
                    </td>
                </tr>
            </table>
        </div>
    @endif
@endsection
