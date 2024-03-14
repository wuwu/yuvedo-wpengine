{{--
  Template Name: Spenden Page
--}}

@extends('layouts.app')
<h1>SPEEEEEEENDEN sie jetzt</h1>
@section('content')
  @while(have_posts()) @php(the_post())
    @include('partials.page-header')
    @include('partials.content-page')
  @endwhile
@endsection
