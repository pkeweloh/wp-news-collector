<?php
/**
 * Latest news sidebar widget.
 *
 * Renders the most recent N items from the news feed.
 * Each item links to its canonical /noticia/{id} page.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_News_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'nc_news_widget',
			__( 'Latest news', 'wp-news-collector' ),
			[
				'description' => __( 'Displays the latest news from the feed.', 'wp-news-collector' ),
				'classname'   => 'nc-news-widget',
			]
		);
	}

	/**
	 * Front-end output.
	 *
	 * @param array<string, mixed> $args     Theme-supplied wrapper args.
	 * @param array<string, mixed> $instance Saved widget settings.
	 */
	public function widget( $args, $instance ): void {
		$title  = apply_filters( 'widget_title', (string) ( $instance['title'] ?? '' ), $instance, $this->id_base );
		$count  = max( 1, min( 10, (int) ( $instance['count'] ?? 5 ) ) );
		$source = sanitize_text_field( (string) ( $instance['source'] ?? '' ) );

		/** @var NC_Item_Repository $repo */
		$repo  = NC_Widget_Registry::items();
		$page  = $repo->get_page( 1, $count, $source );
		$items = $page['items'];

		if ( empty( $items ) ) {
			return;
		}

		echo wp_kses_post( $args['before_widget'] );

		if ( '' !== $title ) {
			echo wp_kses_post( $args['before_title'] ) . esc_html( $title ) . wp_kses_post( $args['after_title'] );
		}

		echo '<ul class="nc-widget-list">';
		foreach ( $items as $item ) {
			$permalink   = NC_Plugin::item_permalink( (int) $item['id'] );
			$thumb       = $this->first_thumb( $item );
			$text        = $this->widget_text( (string) ( $item['text'] ?? '' ), 160 );
			$date_label  = NC_Template_Helpers::format_date_es( (string) $item['published_at'] );
			$source_name = esc_html( (string) $item['source_name'] );
			?>
			<li class="nc-widget-item">
				<a href="<?php echo esc_url( $permalink ); ?>" class="nc-widget-item__link">
					<?php if ( '' !== $thumb ) : ?>
						<img class="nc-widget-item__thumb" src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy" />
					<?php endif; ?>
					<div class="nc-widget-item__body">
						<?php if ( '' !== $source_name ) : ?>
							<span class="nc-widget-item__source"><?php echo $source_name; // already escaped ?></span>
						<?php endif; ?>
						<?php if ( '' !== $text ) : ?>
							<span class="nc-widget-item__text"><?php echo esc_html( $text ); ?></span>
						<?php endif; ?>
						<time class="nc-widget-item__time"><?php echo esc_html( $date_label ); ?></time>
					</div>
				</a>
			</li>
			<?php
		}
		echo '</ul>';

		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Settings form (back-end).
	 *
	 * @param array<string, mixed> $instance Current settings.
	 */
	public function form( $instance ): void {
		$title  = (string) ( $instance['title'] ?? __( 'Latest news', 'wp-news-collector' ) );
		$count  = (int) ( $instance['count'] ?? 5 );
		$source = (string) ( $instance['source'] ?? '' );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'wp-news-collector' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"><?php esc_html_e( 'Number of items (1–10):', 'wp-news-collector' ); ?></label>
			<input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>"
				type="number" min="1" max="10" value="<?php echo (int) $count; ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'source' ) ); ?>"><?php esc_html_e( 'Filter by source (handle, optional):', 'wp-news-collector' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'source' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'source' ) ); ?>"
				type="text" value="<?php echo esc_attr( $source ); ?>" />
		</p>
		<?php
	}

	/**
	 * Sanitize and save widget settings.
	 *
	 * @param array<string, mixed> $new_instance New settings from form.
	 * @param array<string, mixed> $old_instance Previous settings.
	 * @return array<string, mixed>
	 */
	public function update( $new_instance, $old_instance ): array {
		return [
			'title'  => sanitize_text_field( (string) ( $new_instance['title'] ?? '' ) ),
			'count'  => max( 1, min( 10, (int) ( $new_instance['count'] ?? 5 ) ) ),
			'source' => sanitize_text_field( (string) ( $new_instance['source'] ?? '' ) ),
		];
	}

	/** @see NC_Shortcode::widget_text() */
	private function widget_text( string $raw_html, int $max_chars ): string {
		$text = preg_replace( '~<br\s*/?>~i', ' ', $raw_html ) ?? $raw_html;
		$text = preg_replace( '~</(?:p|div|li|h[1-6]|blockquote)>~i', ' ', $text ) ?? $text;
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/\s+/', ' ', trim( $text ) );
		return mb_strimwidth( $text, 0, $max_chars, '…' );
	}

	/**
	 * Return the first usable thumbnail URL for an item, or ''.
	 *
	 * @param array<string, mixed> $item
	 */
	private function first_thumb( array $item ): string {
		// Prefer article cover image.
		$article = is_array( $item['article'] ?? null ) ? $item['article'] : null;
		if ( is_array( $article ) && '' !== (string) ( $article['image_url'] ?? '' ) ) {
			return (string) $article['image_url'];
		}
		$images = (array) ( $item['images'] ?? [] );
		if ( ! empty( $images ) ) {
			return (string) $images[0];
		}
		foreach ( (array) ( $item['videos'] ?? [] ) as $v ) {
			$v = (array) $v;
			if ( '' !== (string) ( $v['poster_url'] ?? '' ) ) {
				return (string) $v['poster_url'];
			}
		}
		return '';
	}
}

/**
 * Minimal service locator so NC_News_Widget can access the item repo
 * without coupling to NC_Plugin's constructor chain.
 */
class NC_Widget_Registry {

	private static ?NC_Item_Repository $items = null;

	public static function set_items( NC_Item_Repository $repo ): void {
		self::$items = $repo;
	}

	public static function items(): NC_Item_Repository {
		if ( null === self::$items ) {
			self::$items = new NC_Item_Repository();
		}
		return self::$items;
	}
}
