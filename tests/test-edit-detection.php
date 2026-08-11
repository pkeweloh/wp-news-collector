<?php
/**
 * Standalone test for edit detection: runs without WordPress.
 * Usage: php tests/test-edit-detection.php
 *
 * Only NC_News_Processor::content_hash() is exercised, so nothing here touches
 * the network or the database. The property that matters is that a re-fetch of
 * an untouched post hashes identically even though Telegram hands out a fresh
 * signed media URL every time.
 */

// Minimal WordPress stubs.
define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

require_once __DIR__ . '/../includes/class-feed-parser.php';
require_once __DIR__ . '/../includes/class-news-processor.php';

$failures = [];

function assert_eq( string $label, $actual, $expected, array &$failures ): void {
	if ( $actual !== $expected ) {
		$failures[] = $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true );
	} else {
		echo "  ok: $label\n";
	}
}

/** Parse a single <description> the way the ingest cycle would. */
function parse_one( string $description ): array {
	$xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><item>'
		. '<guid>https://t.me/aldi/500</guid>'
		. '<pubDate>Tue, 01 Apr 2025 10:00:00 +0000</pubDate>'
		. '<description><![CDATA[' . $description . ']]></description>'
		. '</item></channel></rss>';
	$items = NC_Feed_Parser::parse_feed( $xml, 'aldi' );
	return $items[0];
}

function text_half( string $hash ): string {
	return substr( $hash, 0, (int) strpos( $hash, ':' ) );
}

function media_half( string $hash ): string {
	return substr( $hash, (int) strpos( $hash, ':' ) + 1 );
}

$original = '<p>Oferta de hoy: 3 euros</p><img src="https://cdn4.telesco.pe/file/AAA1"/>';
$base     = NC_News_Processor::content_hash( parse_one( $original ) );

// 1) Same post fetched again, but Telegram re-signed the image URL: not an edit.
$resigned = NC_News_Processor::content_hash(
	parse_one( '<p>Oferta de hoy: 3 euros</p><img src="https://cdn4.telesco.pe/file/ZZZ9"/>' )
);
assert_eq( 'resigned media URL is not an edit', $resigned, $base, $failures );

// 2) Identical description: not an edit.
assert_eq( 'identical description is not an edit', NC_News_Processor::content_hash( parse_one( $original ) ), $base, $failures );

// 3) Text edited, media untouched: text half moves, media half stays.
$text_edit = NC_News_Processor::content_hash(
	parse_one( '<p>Oferta de hoy: 2,50 euros</p><img src="https://cdn4.telesco.pe/file/AAA1"/>' )
);
assert_eq( 'text edit changes the text half', text_half( $text_edit ) !== text_half( $base ), true, $failures );
assert_eq( 'text edit keeps the media half', media_half( $text_edit ), media_half( $base ), $failures );

// 4) A photo added in the edit: media half moves, so enrichment must run again.
$media_edit = NC_News_Processor::content_hash(
	parse_one( '<p>Oferta de hoy: 3 euros</p><img src="https://cdn4.telesco.pe/file/AAA1"/><img src="https://cdn4.telesco.pe/file/BBB2"/>' )
);
assert_eq( 'added image changes the media half', media_half( $media_edit ) !== media_half( $base ), true, $failures );
assert_eq( 'added image keeps the text half', text_half( $media_edit ), text_half( $base ), $failures );

// 5) Whitespace-only reflow is not an edit.
$reflow = NC_News_Processor::content_hash(
	parse_one( "<p>Oferta de hoy:   3 euros</p>\n<img src=\"https://cdn4.telesco.pe/file/AAA1\"/>" )
);
assert_eq( 'whitespace reflow is not an edit', $reflow, $base, $failures );

// 6) An edited article card must re-run the OG scrape, so it belongs to the media half.
$with_article = parse_one(
	'<p><a href="https://news.example.com/a-42">https://news.example.com/a-42</a></p>'
	. '<blockquote><img src="https://news.example.com/cover.jpg"/><b>Example News</b><br/><b>Titular</b><br/>Resumen.</blockquote>'
);
$article_edit = parse_one(
	'<p><a href="https://news.example.com/a-42">https://news.example.com/a-42</a></p>'
	. '<blockquote><img src="https://news.example.com/cover.jpg"/><b>Example News</b><br/><b>Otro titular</b><br/>Resumen.</blockquote>'
);
assert_eq(
	'article title change moves the media half',
	media_half( NC_News_Processor::content_hash( $article_edit ) ) !== media_half( NC_News_Processor::content_hash( $with_article ) ),
	true,
	$failures
);

echo "\n";
if ( empty( $failures ) ) {
	echo "All edit-detection assertions passed.\n";
	exit( 0 );
}
foreach ( $failures as $f ) {
	echo "FAIL: $f\n";
}
exit( 1 );
