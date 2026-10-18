<x-mail::message>
# Good morning

Here is {{ $businessName }} as the day begins, {{ $figures['date'] }}.

<x-mail::panel>
**Yesterday's sales:** BDT {{ number_format($figures['yesterday_sales'], 2) }}

**Cash right now (all accounts):** BDT {{ number_format($figures['cash_now'], 2) }}

**Tickets past their clock:** {{ $figures['tickets_overdue'] }}
</x-mail::panel>

@if (count($figures['due_this_week']) > 0)
**Standing costs due within a week:**

<x-mail::table>
| What | Due | Amount |
|:-----|:----|-------:|
@foreach ($figures['due_this_week'] as $due)
| {{ $due['name'] }} | {{ \App\Support\DayFirstDate::show($due['due_on']) }} | {{ number_format($due['amount'], 2) }} |
@endforeach
</x-mail::table>
@endif

Open **Business health** in the ERP for everything waiting on a person.

{{ $businessName }}
</x-mail::message>
