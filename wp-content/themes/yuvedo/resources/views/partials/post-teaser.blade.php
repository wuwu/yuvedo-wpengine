@php
$classes = [
    'post-teaser',
    'bg-white',
    'rounded-lg',
    'p-2',
    'md:p-4'
];
$excerpt = get_the_excerpt();

$excerpt = substr($excerpt, 0, 90);
$theExcerpt = substr($excerpt, 0, strrpos($excerpt, ' '));
@endphp

<article @php post_class($classes) @endphp>
  <a class="text-gray-900" href="{{ the_permalink() }}">
    <div class="entry-content flex flex-col justify-center">
      <div class="h-auto w-auto pb-4 md:w-1/1">
        @php if(has_post_thumbnail()) { the_post_thumbnail('full',array('class' => 'project-teaser-image object-cover rounded-lg') ); } @endphp
      </div>
      <h3 class="entry-title pb-4 text-md">{!! get_the_title() !!}</h3>
      <p>{{ $theExcerpt}}</p>
      <div class="inline-flex items-center">
        <a href="{{ get_permalink() }}" class="border-solid border-2 border-primary rounded-md font-semibold text-primary px-4 py-2 mt-2 hover:text-white hover:border-primaryhover hover:bg-primaryhover">{{  pll__('Read More') }}</a>
      </div>    </div>
  </a>
</article>
