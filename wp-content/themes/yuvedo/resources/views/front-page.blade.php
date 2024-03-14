@extends('layouts.app')

@section('content')
  @while(have_posts()) @php(the_post())
    @include('partials.page-header')
    <?php $sub_headline = get_field('sub_headline');
    ?>
    @field('sub_headline')
    @if($sub_headline)
      <h2>{{ $sub_headline }}</h2>
    @endif

    {!! the_content() !!}

  @endwhile
  <h3 class="font-mono text-sky-400">front-page.blade.php</h3>
@endsection
