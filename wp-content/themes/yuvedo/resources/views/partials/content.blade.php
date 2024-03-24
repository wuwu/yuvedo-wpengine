<article @php(post_class('mb-16'))>
  <header>
    <h2 class="entry-title text-xl py-4 font-semibold">
      <a href="{{ get_permalink() }}">
        {!! $title !!}
      </a>
    </h2>
  </header>

  <div class="entry-summary bg-white rounded-md p-4">
    <div class="featured-image rounded-t-lg">@php(the_post_thumbnail())</div>
    <div class="entry-content p-3">
    @php(the_excerpt())
    </div>
  </div>
  @if (defined('WP_DEBUG') && WP_DEBUG)
    <h3 class="font-mono text-sky-400">content.blade.php</h3>
  @endif

</article>
