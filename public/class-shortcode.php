<?php
/**
 * [news_feed] shortcode.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Shortcode {

	public function __construct( private NC_Item_Repository $items ) {}

	public function register(): void {
		add_shortcode( 'news_feed', [ $this, 'render' ] );
		add_shortcode( 'news_widget', [ $this, 'render_widget' ] );
		add_shortcode( 'news_sources', [ $this, 'render_sources' ] );
	}

	/**
	 * @param array<string, mixed>|string $atts
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			[
				'source'      => '',
				'limit'       => 20,
				'show_images' => 'true',
				'show_videos' => 'true',
			],
			is_array( $atts ) ? $atts : [],
			'news_feed'
		);

		$source       = sanitize_text_field( (string) $atts['source'] );
		$limit        = max( 1, min( 200, (int) $atts['limit'] ) );
		$show_images  = $this->truthy( $atts['show_images'] );
		$show_videos  = $this->truthy( $atts['show_videos'] );

		$page     = $this->items->get_page( 1, $limit, $source );
		$items    = $page['items'];
		$has_next = (bool) $page['has_next'];

		wp_enqueue_style( 'nc-public', NC_PLUGIN_URL . 'assets/css/public.css', [], NC_VERSION );
		wp_enqueue_script( 'nc-public', NC_PLUGIN_URL . 'assets/js/public.js', [], NC_VERSION, true );
		wp_localize_script(
			'nc-public',
			'NC_DATA',
			[
				'restUrl'    => esc_url_raw( rest_url( 'nc/v1/item/' ) ),
				'feedUrl'    => esc_url_raw( rest_url( 'nc/v1/feed' ) ),
				'slug'       => NC_Rewrite::slug(),
				'queryVar'   => NC_Rewrite::QUERY_VAR,
				'pretty'     => (bool) get_option( 'permalink_structure' ),
				'home'       => esc_url_raw( home_url( '/' ) ),
				'hasNext'    => $has_next,
				'page'       => 1,
				'pageSize'   => $limit,
				'source'     => $source,
			]
		);

		ob_start();
		include NC_Template_Loader::locate( 'feed.php' );
		return (string) ob_get_clean();
	}

	/**
	 * [news_sources title="Fuentes"]
	 * Standalone sources panel: same look as the old feed sidebar.
	 * Place it in any sidebar widget area via the Shortcode block.
	 *
	 * @param array<string, mixed>|string $atts
	 */
	public function render_sources( $atts ): string {
		$atts  = shortcode_atts( [ 'title' => __( 'Sources', 'wp-news-collector' ) ], is_array( $atts ) ? $atts : [], 'news_sources' );
		$title = sanitize_text_field( (string) $atts['title'] );

		// List the configured sources in a fixed order (by id, ASC), independent
		// of feed activity, so the panel never reshuffles as items come in.
		$sources = [];
		foreach ( ( new NC_Source_Repository() )->get_active() as $row ) {
			$handle = NC_Feed_Parser::source_from_url( (string) $row['url'] );
			$name   = '' !== (string) $row['name'] ? (string) $row['name'] : $handle;
			if ( '' !== $name && ! isset( $sources[ $name ] ) ) {
				$sources[ $name ] = $handle;
			}
		}

		if ( empty( $sources ) ) {
			return '';
		}

		wp_enqueue_style( 'nc-public', NC_PLUGIN_URL . 'assets/css/public.css', [], NC_VERSION );

		ob_start();
		?>
		<div class="nc-feed-sidebar">
			<?php if ( '' !== $title ) : ?>
				<h2 class="nc-feed-sidebar__title"><?php echo esc_html( $title ); ?></h2>
			<?php endif; ?>
			<ul class="nc-feed-source-list">
				<?php foreach ( $sources as $name => $handle ) : ?>
				<li class="nc-feed-source-item">
					<span class="nc-feed-source-dot"></span>
					<div class="nc-feed-source-body">
						<span class="nc-feed-source-name"><?php echo esc_html( $name ); ?></span>
						<?php if ( '' !== $handle ) : ?>
						<a class="nc-feed-source-tg"
							href="https://t.me/<?php echo esc_attr( $handle ); ?>"
							target="_blank"
							rel="noreferrer noopener"
							aria-label="<?php printf( esc_attr__( 'View %s on Telegram', 'wp-news-collector' ), esc_html( $name ) ); ?>">
							<?php echo NC_Template_Helpers::telegram_svg(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php esc_html_e( 'Follow on Telegram', 'wp-news-collector' ); ?>
						</a>
						<?php endif; ?>
					</div>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * [news_widget count="5" source="" title="Últimas noticias"]
	 * Compact news list: use anywhere via the Shortcode block in Gutenberg.
	 *
	 * @param array<string, mixed>|string $atts
	 */
	public function render_widget( $atts ): string {
		$atts = shortcode_atts(
			[
				'count'  => 5,
				'source' => '',
				'title'  => '',
			],
			is_array( $atts ) ? $atts : [],
			'news_widget'
		);

		$count  = max( 1, min( 10, (int) $atts['count'] ) );
		$source = sanitize_text_field( (string) $atts['source'] );
		$title  = sanitize_text_field( (string) $atts['title'] );

		$page  = $this->items->get_page( 1, $count, $source );
		$items = $page['items'];

		if ( empty( $items ) ) {
			return '';
		}

		wp_enqueue_style( 'nc-public', NC_PLUGIN_URL . 'assets/css/public.css', [], NC_VERSION );

		ob_start();
		if ( '' !== $title ) {
			echo '<h3 class="nc-widget-title">' . esc_html( $title ) . '</h3>';
		}
		echo '<ul class="nc-widget-list">';
		foreach ( $items as $item ) {
			$permalink   = NC_Plugin::item_permalink( (int) $item['id'] );
			$thumb       = $this->first_thumb( $item );
			$text        = $this->widget_text( (string) ( $item['text'] ?? '' ), 160 );
			$date_label  = NC_Template_Helpers::format_date_es( (string) $item['published_at'] );
			$source_name = (string) $item['source_name'];
			?>
			<li class="nc-widget-item">
				<a href="<?php echo esc_url( $permalink ); ?>" class="nc-widget-item__link">
					<?php if ( '' !== $thumb ) : ?>
						<img class="nc-widget-item__thumb" src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy" />
					<?php endif; ?>
					<div class="nc-widget-item__body">
						<?php if ( '' !== $source_name ) : ?>
							<span class="nc-widget-item__source"><?php echo esc_html( $source_name ); ?></span>
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
		return (string) ob_get_clean();
	}

	/**
	 * Extract a clean single-line text preview from an item's HTML text field.
	 * Block-level tags and <br> are replaced with a space before stripping so
	 * that words don't concatenate (e.g. "Texto<br>más texto" → "Texto más texto").
	 */
	private function widget_text( string $raw_html, int $max_chars ): string {
		// Replace line-break and block-closing tags with a space.
		$text = preg_replace( '~<br\s*/?>~i', ' ', $raw_html ) ?? $raw_html;
		$text = preg_replace( '~</(?:p|div|li|h[1-6]|blockquote)>~i', ' ', $text ) ?? $text;
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		// Collapse multiple spaces/newlines into a single space.
		$text = (string) preg_replace( '/\s+/', ' ', trim( $text ) );
		return mb_strimwidth( $text, 0, $max_chars, '…' );
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private function first_thumb( array $item ): string {
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

	private function truthy( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$str = strtolower( trim( (string) $value ) );
		return in_array( $str, [ '1', 'true', 'yes', 'on' ], true );
	}
}
