<?php
/**
 * Plugin Name:       AuthorArticleList
 * Plugin URI:        https://github.com/Firmware-Repairman/author-article-list
 * Description:       A plugin that displays a list of authors and their articles published in the specified period on the settings page.
 * Version:           1.4
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
        'list of Articles by Author',      // Menu title
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

    // Add a nonce for security.
    $nonce = wp_create_nonce('dcr_nonce');

    // Find the checked option
    $range = get_option('aal_range', '1 year ago');

    $selections = [
        'all time (takes a couple of minutes)' => '1/1/1970',
        '2 years ago' => '2 years ago',
        '1 year ago' => '1 year ago',
        '6 months ago' => '6 months ago',
        '3 months ago' => '3 months ago',
        '1 month ago' => '1 month ago',
    ];
    ?>

    <form>
        <?php
        foreach ($selections as $key => $value) {
            echo "<input type='radio' name='RadioRange' value='$key'".
                ($range == $key ? " checked" : "") . ">$key";
        }
        ?>
    </form>
    <button id="aal-download-csv" class="button button-primary" style="margin-top: 10px;">Download CSV</button>

    <div id="aal-content-area" class="author-article-list-admin-container">


    <!-- The inline JavaScript to handle the AJAX request -->
    <script>
        jQuery(document).ready(function($) {

            // Function to load content based on the selected value
            function load_content(value) {
                console.log("value");
                console.log(value);
                // Get the nonce value from the PHP code.
                var nonce = '<?php echo esc_js($nonce); ?>';

                // Make the AJAX request
                $.ajax({
                    url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                    type: 'post',
                    data: {
                        action: 'aal_filter_query', // The action hook defined in PHP
                        range: value,
                        security: nonce
                    },
                    success: function(response) {
                        $('#aal-content-area').html(response);
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        console.error("AJAX Error: " + textStatus, errorThrown);
                        $('#aal-content-area').html('<p class="dcr-error-text text-center text-red-500">Error loading content. Please try again.</p>');
                    }
                });
            }

            // Initial content load on page load
            load_content($('input[name="RadioRange"]:checked').val());

            // Listen for changes on the radio buttons
            $('input[name="RadioRange"]').on('change', function() {
                var selected_value = $(this).val();
                load_content(selected_value);
            });

            // Handle CSV download
            $('#aal-download-csv').on('click', function(e) {
                e.preventDefault();
                var range = $('input[name="RadioRange"]:checked').val();
                var nonce = '<?php echo esc_js($nonce); ?>';
                window.location.href = '<?php echo esc_url(admin_url('admin-ajax.php')); ?>?action=aal_download_csv&range=' + encodeURIComponent(range) + '&security=' + nonce;
            });
        });
    </script>
    <?php
}

/**
 * Get report data for a specific range.
 *
 * @param string $range The date range string (e.g., '1 year ago').
 * @return array Array of objects containing author details and their articles.
 */
function aal_get_report_data($range) {
    global $wpdb;

    // Set the time to a particular range prior to the current date.
    $prior_date = date('Y-m-d H:i:s', strtotime($range));
    $category_chinese = 75862;
    $category_en_espanol = 9178;

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
    $data = [];

    if ($authors) {
        foreach ($authors as $author) {
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
                    WHERE (tt.term_id = %d
                    OR tt.term_id = %d)
                    AND tt.taxonomy = 'category'
                )
                ORDER BY post_date DESC
                ",
                $author->ID,
                $prior_date,
                $category_chinese,
                $category_en_espanol
            );

            $articles = $wpdb->get_results($articles_query);
            if (!empty($articles)) {
                $author->articles = $articles;
                $data[] = $author;
            }
        }
    }
    return $data;
}

function aal_handle_ajax_query() {
    // Verify the nonce for security.
    check_ajax_referer('dcr_nonce', 'security');

    $range  = isset($_POST['range']) ? sanitize_text_field($_POST['range']) : '1 year ago';
    update_option('aal_range', $range);

    $authors = aal_get_report_data($range);

    echo "
       <div class='wrap'>
       <h1>Articles published since $range sorted by author</h1>
       ";

    if (empty($authors)) {
        echo "<p>No authors found with articles published since $range.</p>";
    } else {
        echo '<div class="author-article-list-admin-container">';
        foreach ($authors as $author) {
            $author_name = esc_html($author->display_name);
            $articles = $author->articles;
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

    // Always die() at the end of the AJAX handler to prevent unwanted output.
    wp_die();
}

// Hook the AJAX handler for both logged-in and logged-out users.
add_action('wp_ajax_aal_filter_query', 'aal_handle_ajax_query');
add_action('wp_ajax_nopriv_aal_filter_query', 'aal_handle_ajax_query');

/**
 * Handle CSV download request.
 */
function aal_handle_csv_download() {
    check_ajax_referer('dcr_nonce', 'security');

    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $range = isset($_GET['range']) ? sanitize_text_field($_GET['range']) : '1 year ago';

    $authors_data_raw = aal_get_report_data($range);

    // Prepare data structure
    $authors_data = [];
    $range_ts = strtotime($range);

    $min_ts = $range_ts;
    if (date('Y', $range_ts) == 1970 && !empty($authors_data_raw)) {
        $timestamps = [];
        foreach ($authors_data_raw as $author) {
            foreach ($author->articles as $article) {
                $timestamps[] = strtotime($article->post_date);
            }
        }
        if ($timestamps) {
            $min_ts = min($timestamps);
        }
    }

    $start_ts = mktime(0, 0, 0, date('n', $min_ts), 1, date('Y', $min_ts));

    $months = [];
    $current_ts = $start_ts;
    $end_ts = mktime(0, 0, 0, date('n'), 1, date('Y'));

    while ($current_ts <= $end_ts) {
        $months[date('Y-m', $current_ts)] = date('M Y', $current_ts);
        $current_ts = strtotime('+1 month', $current_ts);
    }

    foreach ($authors_data_raw as $author) {
        $name = $author->display_name;
        if (!isset($authors_data[$name])) $authors_data[$name] = ['name' => $name, 'total' => 0, 'counts' => []];

        foreach ($author->articles as $article) {
            $article_ts = strtotime($article->post_date);
            $key = date('Y-m', $article_ts);
            if (!isset($authors_data[$name]['counts'][$key])) $authors_data[$name]['counts'][$key] = 0;
            $authors_data[$name]['counts'][$key]++;
            $authors_data[$name]['total']++;
        }
    }

    usort($authors_data, function($a, $b) { return $b['total'] - $a['total']; });

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="author_articles.csv"');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, array_merge(['Name'], array_values($months)));
    foreach ($authors_data as $author) {
        $row = [$author['name']];
        foreach (array_keys($months) as $m) $row[] = $author['counts'][$m] ?? 0;
        fputcsv($fp, $row);
    }
    fclose($fp);
    wp_die();
}
add_action('wp_ajax_aal_download_csv', 'aal_handle_csv_download');
?>
