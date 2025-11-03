<?php
/**
 * Single Event Template
 *
 * Provides action hooks for third-party integration (dm_events_before_single_event,
 * dm_events_after_single_event, dm_events_after_event_article, dm_events_related_events).
 * Renders breadcrumbs and taxonomy badges via plugin classes.
 *
 * @package DmEvents
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();

do_action('dm_events_before_single_event');
?>

<div id="primary" class="content-area">
    <main id="main" class="site-main">

        <?php while (have_posts()) : the_post(); ?>

            <?php echo \DmEvents\Core\Breadcrumbs::render(get_the_ID()); ?>

            <article id="post-<?php the_ID(); ?>" <?php post_class('dm-event-single'); ?>>

                <header class="entry-header">
                    <?php echo \DmEvents\Core\Taxonomy_Badges::render_taxonomy_badges(get_the_ID()); ?>
                    <?php the_title('<h1 class="entry-title">', '</h1>'); ?>
                </header>

                <div class="entry-content">
                    <?php
                    the_content();

                    wp_link_pages(array(
                        'before' => '<div class="page-links">' . esc_html__('Pages:', 'dm-events'),
                        'after'  => '</div>',
                    ));
                    ?>
                </div>

                <?php do_action('dm_events_after_event_article'); ?>

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

            <aside>
                <?php
                if (comments_open() || get_comments_number()) {
                    comments_template();
                }

                do_action('dm_events_related_events', get_the_ID());
                ?>
            </aside>

        <?php endwhile; ?>

    </main>
</div>

<?php
get_sidebar();

do_action('dm_events_after_single_event');

get_footer();