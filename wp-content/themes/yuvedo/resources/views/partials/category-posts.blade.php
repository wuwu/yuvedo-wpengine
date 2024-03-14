
@php
    $categories = get_the_category();
    $category_id = !empty($categories) ? $categories[0]->term_id : 0;
    $related_posts = new WP_Query([
        'post_type' => 'post',
        'posts_per_page' => 9, // Adjust the number of posts to display
        'category__in' => [$category_id],
        'post__not_in' => [$post->ID],
    ]);
@endphp

    @if($related_posts->have_posts())
      <div class="rounded-3xl p-2 md:p-8 lg:p-12 my-4 md:my-8 border-separate border-spacing-2 border border-light02">
        <div class="related-posts grid md:grid-cols-2 xl:grid-cols-3 gap-4">
          @while($related_posts->have_posts()) @php $related_posts->the_post() @endphp
            @include('partials.post-teaser')
          @endwhile
        </div>
      </div>
        @php wp_reset_postdata() @endphp
    @endif
<p class="font-mono text-alertinfodark">Current Category ID: {{ $category_id }}</p>
<h3 class="font-mono text-alertinfodark">content-page.blade.php</h3>
