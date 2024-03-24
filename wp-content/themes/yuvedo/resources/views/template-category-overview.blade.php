{{--
  Template Name: Category Overview
--}}

@extends('layouts.app')

@section('content')
  @while(have_posts()) @php(the_post())
    @include('partials.page-header')
    @include('partials.category-posts')
  @endwhile
  @if (defined('WP_DEBUG') && WP_DEBUG)
    <h3 class="font-mono text-sky-400">template-category-overview.blade.php</h3>
  @endif
@endsection
