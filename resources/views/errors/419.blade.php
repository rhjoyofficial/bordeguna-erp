{{--
    ⚠⚠ THE MOST COMMON ONE IN PRACTICE, AND THE MOST CONFUSING. Laravel's own
    wording for this is "Page Expired", which tells somebody who left a tab open
    over lunch precisely nothing. It is a stale security token, the fix is to
    sign in again, and nothing they typed was wrong.
--}}
@extends('errors.layout')

@section('code', 'Error 419')
@section('title', 'Your session has expired')
@section('message', 'You were signed out for security, most likely because this page was left open for a while. Sign in again and repeat what you were doing - nothing you typed was wrong.')
