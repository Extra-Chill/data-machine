<?php
/**
 * Single Event Template
 *
 * Template for displaying single dm_events posts. This template inherits the theme's
 * styling and structure while providing proper display for Event Details blocks.
 *
 * @package DmEvents
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

get_header(); ?>

<div id="primary" class="content-area">
    <main id="main" class="site-main">

        <?php while (have_posts()) : the_post(); ?>

            <article id="post-<?php the_ID(); ?>" <?php post_class('dm-event-single'); ?>>
                
                <header class="entry-header">
                    <?php the_title('<h1 class="entry-title">', '</h1>'); ?>
                </header>

                <?php 
                $main_events_url = \DmEvents\Admin\Settings_Page::get_main_events_page_url();
                if (!empty($main_events_url)) : ?>
                    <nav class="dm-events-back-nav" aria-label="<?php esc_attr_e('Event Navigation', 'dm-events'); ?>">
                        <a href="<?php echo esc_url($main_events_url); ?>" class="dm-events-back-link">
                            <?php esc_html_e('← Back to Events', 'dm-events'); ?>
                        </a>
                    </nav>
                <?php endif; ?>

                <div class="entry-content">
                    <?php
                    the_content();
                    
                    wp_link_pages(array(
                        'before' => '<div class="page-links">' . esc_html__('Pages:', 'dm-events'),
                        'after'  => '</div>',
                    ));
                    ?>
                </div>

                <?php if (get_edit_post_link()) : ?>
                    <footer class="entry-footer">
                        <?php
                        edit_post_link(
                            sprintf(
                                wp_kses(
                                    __('Edit <span class="screen-reader-text">%s</span>', 'dm-events'),
                                    array(
                                        'span' => array(
                                            'class' => array(),
                                        ),
                                    )
                                ),
                                esc_html(get_the_title())
                            ),
                            '<span class="edit-link">',
                            '</span>'
                        );
                        ?>
                    </footer>
                <?php endif; ?>

            </article>

        <?php endwhile; ?>

    </main>
</div>

<?php
get_sidebar();
get_footer();