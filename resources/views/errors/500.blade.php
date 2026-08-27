{{--
    ⛔ NEVER print the exception here. The message can name a table, a column or
    a query, and this page is shown to whoever happened to click.
--}}
@extends('errors.layout')

@section('code', 'Error 500')
@section('title', 'Something went wrong at our end')
@section('message', 'This is a fault in the system, not something you did. It has been recorded. Nothing you were part-way through has been saved.')
