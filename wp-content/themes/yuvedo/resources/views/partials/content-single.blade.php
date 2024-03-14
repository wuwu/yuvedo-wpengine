<article @php(post_class('post-full'))>
  <div class="rounded-3xl p-12 my-8 border-separate border-spacing-2 border border-light02 ">
    <a href="{{ wp_get_referer() }}" class="inline-block mb-4 text-primary hover:text-primaryhover">&larr; Back</a>

    <div class="featured-image rounded-t-lg">
      @php(the_post_thumbnail('full',array('class' => 'project-image object-cover w-full rounded-lg') ))
    </div>
    <header>
      <h1 class="p-name text-4xl py-8 font-semibold">
        {!! $title !!}
      </h1>
    </header>
    @php(the_content())
  </div>

  <footer>
    {!! wp_link_pages(['echo' => 0, 'before' => '<nav class="page-nav"><p>' . __('Pages:', 'sage'), 'after' => '</p></nav>']) !!}
  </footer>

  @php(comments_template())
  <h3 class="font-mono text-sky-400">content-single.blade.php</h3>
</article>
