<?php

// app/Controllers/RelatedPostsController.php

namespace App\Controllers;

use WP_Query;


class RelatedPostsController
{
    public function getRelatedPosts($postID, $numberOfPosts = 3)
    {
        $categories = get_the_category($postID);
        $categoryID = !empty($categories) ? $categories[0]->term_id : 0;

        return new WP_Query([
            'post_type' => 'post',
            'posts_per_page' => $numberOfPosts,
            'category__in' => [$categoryID],
            'post__not_in' => [$postID],
        ]);
    }
}
