@extends('layouts.app')

@section('content')
  @while(have_posts()) @php(the_post())
    @include('partials.page-header')
    @includeFirst(['partials.content-page', 'partials.content'])
  @endwhile
  @if (defined('WP_DEBUG') && WP_DEBUG)
    <h3 class="font-mono text-sky-400">page.blade.php</h3>
  @endif
@endsection
