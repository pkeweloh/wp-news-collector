<?php
/**
 * RSS feed parser — faithful port of alerta-boe/app/news_parser.py.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Feed_Parser {

	private const YOUTUBE_PATTERNS = [
		'~youtube\.com/watch\?(?:[^&]*&)*v=([a-zA-Z0-9_-]{11})~',
		'~youtu\.be/([a-zA-Z0-9_-]{11})~',
	];
	private const TELEGRAM_ID_RE = '~/(\d+)(?:\?.*)?$~';
	private const TOO_BIG_RE     = '~too\s+big~i';
	private const FORWARDED_RE   = '~^(Forwarded From|Reenviado de)\b~i';
	private const SEPARATOR_RE   = '~^[.\s]*$~';

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function parse_feed( string $xml, string $source = '' ): array {
		if ( '' === trim( $xml ) ) {
			return [];
		}
		$prev = libxml_use_internal_errors( true );
		$root = simplexml_load_string( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		if ( false === $root ) {
			return [];
		}

		// RSS: <channel><item>; Atom-like RSSHub still puts <item> under root.
		$item_nodes = [];
		if ( isset( $root->channel ) ) {
			foreach ( $root->channel->item as $node ) {
				$item_nodes[] = $node;
			}
		}
		// Fallback in case <channel> wasn't used.
		if ( empty( $item_nodes ) ) {
			foreach ( $root->xpath( '//item' ) ?: [] as $node ) {
				$item_nodes[] = $node;
			}
		}

		$items = [];
		foreach ( $item_nodes as $node ) {
			$guid        = trim( (string) ( $node->guid ?? '' ) );
			if ( '' === $guid ) {
				$guid = trim( (string) ( $node->link ?? '' ) );
			}
			if ( '' === $guid ) {
				continue;
			}
			$pub_date    = trim( (string) ( $node->pubDate ?? '' ) );
			$description = trim( (string) ( $node->description ?? '' ) );
			$items[]     = self::parse_item( $guid, $pub_date, $description, $source );
		}
		return $items;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function parse_item( string $guid, string $pub_date, string $description, string $source ): array {
		$telegram_id  = self::extract_telegram_id( $guid );
		$published_at = self::parse_date( $pub_date );

		$dom = self::load_html( $description );
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		$images      = [];
		$videos      = [];
		$youtube_ids = [];
		$article     = null;

		if ( $body instanceof DOMElement ) {
			// 1) Process blockquotes (snapshot to array since we mutate the DOM).
			$blockquotes = self::nodes_to_array( $body->getElementsByTagName( 'blockquote' ) );
			foreach ( $blockquotes as $bq ) {
				if ( ! $bq instanceof DOMElement || ! $bq->parentNode ) {
					continue;
				}
				$bq_text = trim( $bq->textContent );

				if ( preg_match( self::TOO_BIG_RE, $bq_text ) ) {
					self::handle_too_big_blockquote( $bq, $videos, $dom );
					continue;
				}

				if ( null === $article ) {
					$maybe = self::handle_article_blockquote( $bq );
					if ( null !== $maybe ) {
						$article = $maybe;
					}
				}
				$bq->parentNode->removeChild( $bq );
			}

			// 2) Remaining <video> elements
			foreach ( self::nodes_to_array( $body->getElementsByTagName( 'video' ) ) as $video_el ) {
				if ( ! $video_el instanceof DOMElement || ! $video_el->parentNode ) {
					continue;
				}
				$src    = trim( (string) $video_el->getAttribute( 'src' ) );
				$poster = trim( (string) $video_el->getAttribute( 'poster' ) );
				if ( '' !== $src ) {
					$videos[] = [
						'original_url' => $src,
						'poster_url'   => $poster,
						'catbox_url'   => '',
						'status'       => 'pending',
					];
				}
				if ( '' !== $poster ) {
					self::remove_imgs_with_src( $dom, $poster );
				}
				$video_el->parentNode->removeChild( $video_el );
			}

			// 3) Remaining <img> elements → images
			foreach ( self::nodes_to_array( $body->getElementsByTagName( 'img' ) ) as $img ) {
				if ( ! $img instanceof DOMElement || ! $img->parentNode ) {
					continue;
				}
				$src = trim( (string) $img->getAttribute( 'src' ) );
				if ( '' !== $src ) {
					$images[] = $src;
				}
				$img->parentNode->removeChild( $img );
			}

			// 4) Collect YouTube IDs from <a href>
			foreach ( self::nodes_to_array( $body->getElementsByTagName( 'a' ) ) as $a_el ) {
				if ( ! $a_el instanceof DOMElement ) {
					continue;
				}
				$href = trim( (string) $a_el->getAttribute( 'href' ) );
				if ( '' === $href ) {
					continue;
				}
				$yt = self::extract_youtube_id( $href );
				if ( '' !== $yt && ! in_array( $yt, $youtube_ids, true ) ) {
					$youtube_ids[] = $yt;
				}
			}

			// 5) Serialize remaining body to safe HTML
			$text = trim( self::serialize_safe_html( $body ) );
		} else {
			$text = '';
		}

		return [
			'guid'            => $guid,
			'telegram_id'     => $telegram_id,
			'source'          => $source,
			'source_name'     => $source,
			'raw_description' => $description,
			'text'            => $text,
			'images'          => $images,
			'videos'          => $videos,
			'youtube_ids'     => $youtube_ids,
			'article'         => $article,
			'published_at'    => $published_at,
			'fetched_at'      => gmdate( 'Y-m-d H:i:s' ),
		];
	}

	/**
	 * Capture poster + adjacent <video> as a single 'too_big' entry.
	 *
	 * @param array<int, array<string, string>> $videos Modified in place.
	 */
	private static function handle_too_big_blockquote( DOMElement $bq, array &$videos, DOMDocument $dom ): void {
		$poster       = '';
		$original_url = '';
		$bq_img       = self::first_child_tag( $bq, 'img' );
		if ( $bq_img instanceof DOMElement ) {
			$poster = trim( (string) $bq_img->getAttribute( 'src' ) );
		}

		// Absorb adjacent <video> sibling (previous or next, prefer previous like Python order).
		foreach ( [ 'previous', 'next' ] as $direction ) {
			$sib = self::element_sibling( $bq, $direction );
			if ( $sib instanceof DOMElement && 'video' === strtolower( $sib->nodeName ) ) {
				$src = trim( (string) $sib->getAttribute( 'src' ) );
				if ( '' !== $src ) {
					$original_url = $src;
				}
				if ( '' === $poster ) {
					$poster = trim( (string) $sib->getAttribute( 'poster' ) );
				}
				if ( $sib->parentNode ) {
					$sib->parentNode->removeChild( $sib );
				}
				break;
			}
		}

		if ( '' !== $poster ) {
			self::remove_imgs_with_src( $dom, $poster );
		}

		$videos[] = [
			'original_url' => $original_url,
			'poster_url'   => $poster,
			'catbox_url'   => '',
			'status'       => 'too_big',
		];

		if ( $bq->parentNode ) {
			$bq->parentNode->removeChild( $bq );
		}
	}

	/**
	 * Build article payload from an RSSHub-style blockquote.
	 *
	 * @return array<string, string>|null
	 */
	private static function handle_article_blockquote( DOMElement $bq ): ?array {
		// Find article URL via the previous sibling element (<a> or <p>/<a>).
		$prev = self::element_sibling( $bq, 'previous' );
		$article_url = '';
		if ( $prev instanceof DOMElement ) {
			if ( 'a' === strtolower( $prev->nodeName ) ) {
				$article_url = trim( (string) $prev->getAttribute( 'href' ) );
			} else {
				$inner_a = $prev->getElementsByTagName( 'a' );
				foreach ( $inner_a as $a_node ) {
					if ( $a_node instanceof DOMElement ) {
						$href = trim( (string) $a_node->getAttribute( 'href' ) );
						if ( '' !== $href ) {
							$article_url = $href;
							break;
						}
					}
				}
			}
		}

		if ( '' === $article_url ) {
			return null;
		}

		// Extract article image before the global img sweep.
		$article_image = '';
		$bq_img        = self::first_child_tag( $bq, 'img' );
		if ( $bq_img instanceof DOMElement ) {
			$article_image = trim( (string) $bq_img->getAttribute( 'src' ) );
			if ( $bq_img->parentNode ) {
				$bq_img->parentNode->removeChild( $bq_img );
			}
		}

		// Find <b>/<strong> tags: first = source label, second = title.
		$b_tags = [];
		foreach ( self::nodes_to_array( $bq->getElementsByTagName( '*' ) ) as $el ) {
			if ( $el instanceof DOMElement ) {
				$nm = strtolower( $el->nodeName );
				if ( 'b' === $nm || 'strong' === $nm ) {
					$b_tags[] = $el;
				}
			}
		}
		$title = '';
		if ( count( $b_tags ) >= 2 ) {
			$title = trim( $b_tags[1]->textContent );
			foreach ( [ $b_tags[0], $b_tags[1] ] as $to_remove ) {
				if ( $to_remove->parentNode ) {
					$to_remove->parentNode->removeChild( $to_remove );
				}
			}
		} elseif ( ! empty( $b_tags ) ) {
			$title = trim( $b_tags[0]->textContent );
			if ( $b_tags[0]->parentNode ) {
				$b_tags[0]->parentNode->removeChild( $b_tags[0] );
			}
		}

		$excerpt = trim( preg_replace( '~\s+~', ' ', $bq->textContent ) ?? '' );

		return [
			'title'     => $title,
			'text'      => $excerpt,
			'url'       => $article_url,
			'image_url' => $article_image,
			'site_name' => '',
		];
	}

	// -------------------------------------------------------------------------
	// DOM helpers
	// -------------------------------------------------------------------------

	private static function load_html( string $html ): DOMDocument {
		$dom  = new DOMDocument( '1.0', 'UTF-8' );
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="utf-8"?><html><body>' . $html . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $dom;
	}

	/**
	 * @param DOMNodeList<DOMNode> $list
	 * @return array<int, DOMNode>
	 */
	private static function nodes_to_array( DOMNodeList $list ): array {
		$out = [];
		foreach ( $list as $node ) {
			$out[] = $node;
		}
		return $out;
	}

	private static function first_child_tag( DOMElement $parent, string $tag ): ?DOMElement {
		$nodes = $parent->getElementsByTagName( $tag );
		$first = $nodes->item( 0 );
		return $first instanceof DOMElement ? $first : null;
	}

	private static function element_sibling( DOMNode $node, string $direction ): ?DOMElement {
		$sib = 'previous' === $direction ? $node->previousSibling : $node->nextSibling;
		while ( $sib && ! ( $sib instanceof DOMElement ) ) {
			$sib = 'previous' === $direction ? $sib->previousSibling : $sib->nextSibling;
		}
		return $sib instanceof DOMElement ? $sib : null;
	}

	private static function remove_imgs_with_src( DOMDocument $dom, string $src ): void {
		$xpath = new DOMXPath( $dom );
		$list  = $xpath->query( '//img[@src=' . self::xpath_literal( $src ) . ']' );
		if ( false === $list ) {
			return;
		}
		foreach ( self::nodes_to_array( $list ) as $node ) {
			if ( $node instanceof DOMElement && $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	/**
	 * Escape a string for use as an XPath string literal.
	 * Handles values containing both single and double quotes.
	 */
	private static function xpath_literal( string $value ): string {
		if ( false === strpos( $value, "'" ) ) {
			return "'" . $value . "'";
		}
		if ( false === strpos( $value, '"' ) ) {
			return '"' . $value . '"';
		}
		// Has both — build a concat() expression.
		$parts = explode( "'", $value );
		$expr  = [];
		foreach ( $parts as $i => $p ) {
			if ( $i > 0 ) {
				$expr[] = "\"'\"";
			}
			if ( '' !== $p ) {
				$expr[] = "'" . $p . "'";
			}
		}
		return 'concat(' . implode( ',', $expr ) . ')';
	}

	// -------------------------------------------------------------------------
	// Safe HTML serializer (whitelist: b, strong, i, em, a, br, p, span)
	// -------------------------------------------------------------------------

	private static function serialize_safe_html( DOMNode $node ): string {
		$children = [];
		foreach ( $node->childNodes as $child ) {
			$children[] = $child;
		}
		return self::serialize_children( $children );
	}

	/**
	 * @param array<int, DOMNode> $children
	 */
	private static function serialize_children( array $children ): string {
		$parts = [];
		$count = count( $children );
		$idx   = 0;
		while ( $idx < $count ) {
			$child = $children[ $idx ];

			if ( $child instanceof DOMText || $child instanceof DOMCdataSection ) {
				$text_val = $child->nodeValue ?? '';
				$stripped = trim( $text_val );
				if ( '' !== $stripped && preg_match( self::FORWARDED_RE, $stripped ) ) {
					$idx++;
					while ( $idx < $count ) {
						$nxt = $children[ $idx ];
						if ( $nxt instanceof DOMElement ) {
							$nm = strtolower( $nxt->nodeName );
							if ( 'br' === $nm ) {
								$idx++;
								break;
							}
							if ( 'p' === $nm ) {
								break;
							}
						}
						$idx++;
					}
					continue;
				}
				if ( '' !== $stripped && preg_match( self::SEPARATOR_RE, $stripped ) ) {
					$idx++;
					continue;
				}
				$parts[] = htmlspecialchars( $text_val, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$idx++;
				continue;
			}

			if ( $child instanceof DOMElement ) {
				$nm = strtolower( $child->nodeName );
				if ( 'b' === $nm || 'strong' === $nm ) {
					$inner = trim( self::serialize_safe_html( $child ) );
					if ( '' !== $inner ) {
						$parts[] = '<b>' . $inner . '</b>';
					}
				} elseif ( 'i' === $nm || 'em' === $nm ) {
					$inner = trim( self::serialize_safe_html( $child ) );
					if ( '' !== $inner ) {
						$parts[] = '<i>' . $inner . '</i>';
					}
				} elseif ( 'a' === $nm ) {
					$href  = trim( (string) $child->getAttribute( 'href' ) );
					$inner = self::serialize_safe_html( $child );
					if ( '' !== $href && preg_match( '~^https?://~i', $href ) ) {
						$safe_href = htmlspecialchars( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						$parts[]   = '<a href="' . $safe_href . '" target="_blank" rel="noreferrer noopener">' . $inner . '</a>';
					} else {
						$parts[] = $inner;
					}
				} elseif ( 'br' === $nm ) {
					$parts[] = '<br>';
				} elseif ( 'p' === $nm ) {
					$p_text = trim( $child->textContent );
					if ( preg_match( self::FORWARDED_RE, $p_text ) ) {
						// Skip the next sibling <p> if it looks like just a name.
						if ( $idx + 1 < $count ) {
							$nxt = $children[ $idx + 1 ];
							if ( $nxt instanceof DOMElement && 'p' === strtolower( $nxt->nodeName ) ) {
								$nxt_text = $nxt->textContent;
								if ( ! preg_match( '~https?://~i', $nxt_text ) && mb_strlen( trim( $nxt_text ) ) < 60 ) {
									$idx += 2;
									continue;
								}
							}
						}
						$idx++;
						continue;
					}
					if ( '' === $p_text || preg_match( self::SEPARATOR_RE, $p_text ) ) {
						$idx++;
						continue;
					}
					$inner = trim( self::serialize_safe_html( $child ) );
					if ( '' !== $inner ) {
						$parts[] = '<p>' . $inner . '</p>';
					}
				} elseif ( 'span' === $nm ) {
					$parts[] = self::serialize_safe_html( $child );
				} else {
					$parts[] = self::serialize_safe_html( $child );
				}
			}

			$idx++;
		}
		return implode( '', $parts );
	}

	// -------------------------------------------------------------------------
	// Misc helpers
	// -------------------------------------------------------------------------

	private static function extract_telegram_id( string $guid ): int {
		if ( preg_match( self::TELEGRAM_ID_RE, $guid, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	private static function extract_youtube_id( string $url ): string {
		foreach ( self::YOUTUBE_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, $url, $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	private static function parse_date( string $raw ): string {
		if ( '' === $raw ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
		$ts = strtotime( $raw );
		if ( false === $ts ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Extract a source slug from the RSS URL's last path segment.
	 *  http://cloudpi:1200/telegram/channel/espana_eterna → espana_eterna
	 */
	public static function source_from_url( string $rss_url ): string {
		$path = (string) wp_parse_url( $rss_url, PHP_URL_PATH );
		$path = rtrim( $path, '/' );
		if ( '' === $path ) {
			return $rss_url;
		}
		$parts = explode( '/', $path );
		return end( $parts ) ?: $rss_url;
	}
}
