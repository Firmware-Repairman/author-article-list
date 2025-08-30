<?php
/**
 * Plugin Name:       AuthorArticleList
 * Plugin URI:        https://github.com/Firmware-Repairman/author-article-list
 * Description:       A plugin that displays a list of authors and their articles published in the last year on the settings page.
 * Version:           1.1
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Craig Mautner for Mission Local SF
 * Author URI:        https://firmware-repairman.pro
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

 if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Add a new top-level menu item to the admin dashboard.
 */
function aal_add_admin_menu() {
    add_options_page(
        'Author Article List Settings',    // Page title
        'Author Articles',                 // Menu title
        'manage_options',                  // Capability required to see the menu
        'author-article-list',             // Menu slug
        'aal_render_settings_page'         // The function to call to render the page
    );
}
add_action('admin_menu', 'aal_add_admin_menu');

/**
 * Render the settings page content.
 * This is where the database queries and HTML generation happen.
 */
function aal_render_settings_page() {
    // Check if the current user has the 'manage_options' capability.
    if (!current_user_can('manage_options')) {
        return;
    }

    echo '
        <form>
            <input type="radio" name="RadioRange" value="1 month ago">1 month ago
            <input type="radio" name="RadioRange" value="3 months ago">3 months ago
            <input type="radio" name="RadioRange" value="6 months ago">6 months ago
            <input type="radio" name="RadioRange" value="1 year ago">1 year ago
            <input type="radio" name="RadioRange" value="2 years ago">2 years ago
        </form>
    ';

    $set_range_php_file = plugins_url('/set-range.php', __FILE__);
    echo "
        <script src='http://ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js' type='text/javascript'></script>
        <script>
            // Reloads the current page when ajax call is complete
            $(document).ajaxStop(function() {
                location.reload(true);
            });

            const radioButtons = document.querySelectorAll(`input[name='RadioRange']`);

            // Add an event listener to each radio button
            radioButtons.forEach(function(radio) {
                radio.addEventListener('change', function() {
                    var data = {
                        range: range
                    };
                    $.post($set_range_php_file, data);
                });
            });

        </script>
    ';
}

global $wpdb;

// Set the time to a particular range prior to the current date.
$range = get_option('aal_range', '1 year ago');
$prior_date = date('Y-m-d H:i:s', strtotime($range));

// SQL query to get authors who have published an article in the last year.
$authors_query = $wpdb->prepare(
    "
    SELECT DISTINCT u.ID, u.display_name
    FROM {$wpdb->posts} AS p
    JOIN {$wpdb->users} AS u ON p.post_author = u.ID
    WHERE p.post_status = 'publish'
    AND p.post_type = 'post'
    AND p.post_date >= %s
    ORDER BY u.display_name ASC
    ",
    $prior_date
);

$authors = $wpdb->get_results($authors_query);

echo "
    <div class='wrap'>
    <h1>Articles published since $range sorted by author</h1>
    ";

if (empty($authors)) {
    echo '<p>No authors found with articles published in the last year.</p>';
} else {
    echo '<div class="author-article-list-admin-container">';
    foreach ($authors as $author) {
        $author_id = $author->ID;
        $author_name = esc_html($author->display_name);
        $category_chinese = 75862;
        $category_en_espanol = 9178;

        // SQL query to get all articles for the current author within the last year.
        $articles_query = $wpdb->prepare(
            "
            SELECT ID, post_title, post_date
            FROM {$wpdb->posts}
            WHERE post_author = %d
            AND post_status = 'publish'
            AND post_type = 'post'
            AND post_date >= %s
            AND ID NOT IN (
                SELECT tr.object_id
                FROM {$wpdb->term_relationships} AS tr
                INNER JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE (tt.term_id = $category_chinese
                    OR tt.term_id = $category_en_espanol)
                AND tt.taxonomy = 'category'
            )
            ORDER BY post_date DESC
            ",
            $author_id,
            $prior_date
        );

        $articles = $wpdb->get_results($articles_query);
        if (!empty($articles)) {
            $article_count = count($articles);
            echo "<h3>$author_name ($article_count articles)</h3>";
            echo '<ul>';
            foreach ($articles as $article) {
                $article_title = esc_html($article->post_title);
                $article_date = esc_html($article->post_date);
                $article_link = esc_url(get_permalink($article->ID));
                echo "<li><a href='$article_link' target='_blank'>$article_title</a> ($article_date)</li>";
            }
            echo '</ul>';
        } else {
            echo '<p>No articles found for this author in the last year.</p>';
        }
    }
    echo '</div>';
}
echo '</div>
    <style>
        .author-article-list-admin-container h1 {
            border-bottom: 2px solid #ddd;
            padding-bottom: 10px;
        }
        .author-article-list-admin-container h3 {
            margin-top: 25px;
            color: #555;
            font-size: 1.2em;
        }
        .author-article-list-admin-container ul {
            list-style-type: disc;
            margin-left: 20px;
        }
        .author-article-list-admin-container li {
            margin-bottom: 5px;
        }
    </style>';

?>

?>
