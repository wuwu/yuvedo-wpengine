{{--
  Template Name: Category Overview
--}}

@extends('layouts.app')

@section('content')
  @while(have_posts()) @php(the_post())
    @include('partials.page-category-header')
    @include('partials.category-posts')
  @endwhile
  <h3 class="font-mono text-sky-400">page.blade.php</h3>
@endsection
