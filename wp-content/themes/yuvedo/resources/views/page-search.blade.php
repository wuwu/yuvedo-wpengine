@extends('layouts.app')

@section('content')
<h1>SEAAAAAARCH</h1>
  @while(have_posts()) @php(the_post())
    @include('partials.page-header')
    @includeFirst(['partials.content-page', 'partials.content'])
  @endwhile
  <h3 class="font-mono text-sky-400">page.blade.php</h3>
@endsection
