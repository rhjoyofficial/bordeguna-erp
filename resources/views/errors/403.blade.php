@extends('errors.layout')

@section('code', 'Error 403')
@section('title', 'You do not have permission for this')
@section('message', 'Your role does not include this screen. If you need it, ask an Owner to grant the permission - nobody can give it to themselves.')
