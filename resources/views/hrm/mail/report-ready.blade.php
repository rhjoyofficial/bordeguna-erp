@component('mail::message')
# Your report is ready

The report you asked for — **{{ $reportName }}** — was too large to prepare while you waited, so it was rendered in the background.

@component('mail::button', ['url' => $url])
Download the report
@endcomponent

The file is not attached to this email. It holds the same data the report screen is access-controlled for, so it stays inside the system: the link above still requires you to be signed in, and it only ever reaches your own reports.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
