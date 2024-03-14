<article @php(post_class("bg-white rounded-lg border border-grey-300 p-4 mb-6"))>
  <header>
    <h2 class="entry-title text-xl pb-2">
      <a href="{{ get_permalink() }}">
        {!! $title !!}
      </a>
    </h2>
  </header>

  <div class="entry-summary">
    @php(the_excerpt())
  </div>
  @includeWhen(get_post_type() === 'post', 'partials.entry-meta')
</article>
