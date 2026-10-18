@extends('mail.layout')

@section('content')
    {{-- Raw {!! !!} output is safe here only because $project->name is escaped via e()
         before being interpolated into the :project placeholder — the whole sentence is
         translated as one unit (instead of split around the link) to keep German word order
         correct, so the resulting HTML has to be embedded unescaped. --}}
    <h1 style="margin:0 0 20px; font-size:20px; color:#111827;">
        {!! __('Updates for :project', [
            'project' => '<a href="'.route('projects.packages', $project).'" style="color:#4338ca; text-decoration:none;">'.e($project->name).'</a>',
        ]) !!}
    </h1>

    @if ($outdatedSections !== [])
        <div style="font-size:13px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:0.03em; margin-bottom:12px;">{{ __('Outdated packages') }}</div>

        @foreach ($outdatedSections as $section)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb; border-radius:8px; margin-bottom:16px;">
                <tr>
                    <td style="padding:14px 18px;">
                        <div style="margin-bottom:10px;">
                            @include('mail.partials.ecosystem-badge', ['ecosystem' => $section['ecosystem']->value, 'url' => route('projects.packages', ['project' => $project, 'ecosystem' => $section['ecosystem']->value])])
                        </div>

                        @foreach ($section['groups'] as $group)
                            <div style="font-size:12px; font-weight:600; color:#6b7280; margin:10px 0 6px;">{{ $group['label'] }} ({{ count($group['packages']) }})</div>

                            @foreach ($group['packages'] as $pkg)
                                <div style="font-size:13px; padding:4px 0; border-top:1px solid #f3f4f6;">
                                    <a href="{{ $pkg['url'] }}" style="color:#4338ca; text-decoration:none; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $pkg['name'] }}</a>
                                    <span style="color:#9ca3af;"> — </span>
                                    <span style="color:#6b7280;">{{ $pkg['current'] }}</span>
                                    <span style="color:#9ca3af;">→</span>
                                    <span style="color:#111827; font-weight:600;">{{ $pkg['latest'] }}</span>
                                </div>
                            @endforeach
                        @endforeach
                    </td>
                </tr>
            </table>
        @endforeach
    @endif

    @if ($findingsSections !== [])
        <div style="font-size:13px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:0.03em; margin-bottom:12px;">{{ __('Vulnerabilities') }}</div>

        @foreach ($findingsSections as $section)
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #fecaca; border-radius:8px; margin-bottom:16px; background-color:#fef2f2;">
                <tr>
                    <td style="padding:14px 18px;">
                        <div style="margin-bottom:10px;">
                            @include('mail.partials.ecosystem-badge', ['ecosystem' => $section['ecosystem']->value, 'url' => route('projects.packages', ['project' => $project, 'ecosystem' => $section['ecosystem']->value])])
                            <span style="color:#991b1b; font-size:12px; font-weight:600; margin-left:6px;">{{ trans_choice(':count finding|:count findings', count($section['findings']), ['count' => count($section['findings'])]) }}</span>
                        </div>

                        @foreach ($section['findings'] as $finding)
                            <div style="font-size:13px; padding:6px 0; border-top:1px solid #fee2e2;">
                                <div>
                                    <a href="{{ $finding['pkg_url'] }}" style="color:#4338ca; text-decoration:none; font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">{{ $finding['package_name'] }} {{ $finding['package_version'] }}</a>
                                    @include('mail.partials.severity-badge', ['severity' => $finding['severity']])
                                </div>
                                <div style="margin-top:2px;">
                                    @if ($finding['advisory_url'] !== null)
                                        <a href="{{ $finding['advisory_url'] }}" style="color:#6b7280; font-size:12px; text-decoration:none;">{{ $finding['advisory_id'] }}</a>
                                    @else
                                        <span style="color:#6b7280; font-size:12px;">{{ $finding['advisory_id'] }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </td>
                </tr>
            </table>
        @endforeach
    @endif
@endsection
