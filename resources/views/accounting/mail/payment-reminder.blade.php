<x-mail::message>
# Payment reminder

Dear {{ $customer->contact_name ?? $customer->name }},

A gentle reminder from **{{ $businessName }}**: the following {{ count($invoices) === 1 ? 'invoice is' : 'invoices are' }} past due.

<x-mail::table>
| Invoice | Date | Due | Days overdue | Outstanding (BDT) |
|:--|:--|:--|--:|--:|
@foreach ($invoices as $invoice)
| {{ $invoice['number'] }} | {{ $invoice['date'] }} | {{ $invoice['due'] ?? '-' }} | {{ $invoice['days_overdue'] }} | {{ number_format($invoice['outstanding'], 2) }} |
@endforeach
</x-mail::table>

**Total outstanding: {{ number_format($total, 2) }} BDT**

If payment is already on its way, please accept our thanks and ignore this note. For any question about these invoices, simply reply to this email.

Warm regards,<br>
{{ $businessName }}
</x-mail::message>
