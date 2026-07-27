@component('mail::message')
# Payslip for {{ $period }}

Hello {{ $employeeName }},

Your payslip for **{{ $period }}** is attached. Net payable: **{{ $net }}**.

The file is password protected. Your password is **{{ $passwordHint }}**

If anything on it looks wrong, reply to this message or speak to HR — please do
not forward the file.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
