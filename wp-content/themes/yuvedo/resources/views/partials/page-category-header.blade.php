@php
$classes = [
    'post-teaser',
    'bg-white',
    'rounded-lg',
    'p-2',
    'md:p-4'
];
$excerpt = get_the_excerpt();

$excerpt = substr($excerpt, 0, 200);
$theExcerpt = substr($excerpt, 0, strrpos($excerpt, ' '));
@endphp

<div class="page-header pl-8 mb-4">
  <h1 class="text-5xl text-center pb-4">{!! $title !!}</h1>
  <h2 class="text-xl text-center">{{ $excerpt}}</h2>
</div>
