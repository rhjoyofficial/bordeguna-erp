{{--
    A page is its sections, drawn by the store's theme.

    ⚠ The view name comes from StorefrontPage::viewFor, and a theme that declared
    a section type it has no template for fails ThemeTemplatesTest rather than
    rendering a blank. A blank is the exact silent failure the capability
    declaration exists to prevent.
--}}
@extends('themes.'.$theme->key().'.layout')

@section('content')
    @foreach ($sections as $section)
        @include($section['view'], [
            'payload' => $section['payload'],
            'media' => $section['media'],
            'products' => $section['products'],
        ])
    @endforeach
@endsection
