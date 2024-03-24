@extends('layouts.app')

@section('content')
<div class="page-header mb-8 mx-auto text-center max-w-xl p-4 md:p-8 lg:p-12">
  <h1 class="text-5xl text-center pb-4">{!! the_title() !!}</h1>
  <h2 class="text-3xl pb-4">@field('sub_headline')</h2>
  <button class="btn">Kontakt</button>
  <button class="btn btn-primary">Spenden</button>
</div>
<div class="bg-white rounded-3xl p-2 md:p-8 lg:p-12 my-4 md:my-8 ">
  {!! the_content() !!}
</div>
@php
    $latest_posts = new WP_Query([
        'post_type' => 'post',
        'posts_per_page' => 3,
        'orderby'=> 'date',
    ]);
@endphp
    @if($latest_posts->have_posts())

      <div class="rounded-3xl p-2 md:p-8 lg:p-12 my-4 md:my-8 border-separate border-spacing-2 border border-light02">
        <h3 class="pb-4">Lesen sie unsere neuesten Nachrichten</h3>
        <div class="related-posts grid md:grid-cols-2 xl:grid-cols-3 gap-4">
          @while($latest_posts->have_posts()) @php $latest_posts->the_post() @endphp
            @include('partials.post-teaser')
          @endwhile
        </div>
      </div>
        @php wp_reset_postdata() @endphp
    @endif
@if (defined('WP_DEBUG') && WP_DEBUG)
  <h3 class="font-mono text-sky-400">front-page.blade.php</h3>
@endif
    @endsection
