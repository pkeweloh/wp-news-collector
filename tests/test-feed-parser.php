<?php
/**
 * Standalone parser test: runs without WordPress.
 * Usage: php tests/test-feed-parser.php
 */

// Minimal WordPress stubs.
define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

require_once __DIR__ . '/../includes/class-feed-parser.php';

$xml   = file_get_contents( __DIR__ . '/fixtures/sample-feed.xml' );
$items = NC_Feed_Parser::parse_feed( $xml, 'test' );

$failures = [];

function assert_eq( string $label, $actual, $expected, array &$failures ): void {
	if ( $actual !== $expected ) {
		$failures[] = $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true );
	} else {
		echo "  ok: $label\n";
	}
}

echo "Parsed " . count( $items ) . " items (expect 4)\n";
assert_eq( 'item count', count( $items ), 4, $failures );

// Item 1: normal item, one image, anchor link kept in text
$i1 = $items[0];
assert_eq( 'item1.guid',         $i1['guid'], 'https://t.me/test/1001', $failures );
assert_eq( 'item1.telegram_id',  $i1['telegram_id'], 1001, $failures );
assert_eq( 'item1.source',       $i1['source'], 'test', $failures );
assert_eq( 'item1.images count', count( $i1['images'] ), 1, $failures );
assert_eq( 'item1.images[0]',    $i1['images'][0], 'https://example.com/img1.jpg', $failures );
assert_eq( 'item1.videos count', count( $i1['videos'] ), 0, $failures );
assert_eq( 'item1.article',      $i1['article'], null, $failures );
$has_anchor = false !== strpos( $i1['text'], 'href="https://example.com/news"' );
assert_eq( 'item1.text has link', $has_anchor, true, $failures );
$has_b = false !== strpos( $i1['text'], '<b>mundo</b>' );
assert_eq( 'item1.text has bold', $has_b, true, $failures );

// Item 2: article blockquote
$i2 = $items[1];
assert_eq( 'item2.has article',         null !== $i2['article'], true, $failures );
if ( $i2['article'] ) {
	assert_eq( 'item2.article.url',     $i2['article']['url'], 'https://news.example.com/article-42', $failures );
	assert_eq( 'item2.article.title',   $i2['article']['title'], 'Titular del articulo', $failures );
	assert_eq( 'item2.article.image',   $i2['article']['image_url'], 'https://news.example.com/cover.jpg', $failures );
	$excerpt_ok = false !== strpos( $i2['article']['text'], 'Resumen breve' );
	assert_eq( 'item2.article.text contains excerpt', $excerpt_ok, true, $failures );
}
assert_eq( 'item2.images count (article img not in images)', count( $i2['images'] ), 0, $failures );

// Item 3: too_big video
$i3 = $items[2];
assert_eq( 'item3.videos count', count( $i3['videos'] ), 1, $failures );
if ( ! empty( $i3['videos'] ) ) {
	$v = $i3['videos'][0];
	assert_eq( 'item3.video.status',     $v['status'], 'too_big', $failures );
	assert_eq( 'item3.video.original_url', $v['original_url'], 'https://t.me/test/1003/video.mp4', $failures );
	assert_eq( 'item3.video.poster_url', $v['poster_url'], 'https://t.me/test/1003/poster.jpg', $failures );
}
// Poster img must NOT appear as standalone image
assert_eq( 'item3.images count (poster deduped)', count( $i3['images'] ), 0, $failures );

// Item 4: forwarded stripped, youtube ids collected
$i4 = $items[3];
assert_eq( 'item4.youtube_ids count', count( $i4['youtube_ids'] ), 2, $failures );
assert_eq( 'item4.youtube_ids[0]', $i4['youtube_ids'][0], 'dQw4w9WgXcQ', $failures );
assert_eq( 'item4.youtube_ids[1]', $i4['youtube_ids'][1], 'aBcDeFgHiJk', $failures );
$forwarded_stripped = false === stripos( $i4['text'], 'Forwarded' );
assert_eq( 'item4.text strips Forwarded', $forwarded_stripped, true, $failures );

// Source slug
assert_eq( 'source_from_url', NC_Feed_Parser::source_from_url( 'http://host:1200/telegram/channel/espana_eterna' ), 'espana_eterna', $failures );

// Inline: <audio> extraction (not in the shared fixture).
$audio_xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><item>'
	. '<guid>https://t.me/test/2001</guid>'
	. '<description><![CDATA[<p>Con audio</p><audio src="https://t.me/test/2001/voice.ogg"></audio>]]></description>'
	. '</item></channel></rss>';
$audio_items = NC_Feed_Parser::parse_feed( $audio_xml, 'test' );
assert_eq( 'audio.item count', count( $audio_items ), 1, $failures );
if ( ! empty( $audio_items ) ) {
	$au = $audio_items[0];
	assert_eq( 'audio.count', count( $au['audios'] ), 1, $failures );
	if ( ! empty( $au['audios'] ) ) {
		assert_eq( 'audio.original_url', $au['audios'][0]['original_url'], 'https://t.me/test/2001/voice.ogg', $failures );
		assert_eq( 'audio.status', $au['audios'][0]['status'], 'pending', $failures );
	}
}

echo "\n";
if ( empty( $failures ) ) {
	echo "ALL PASS\n";
	exit( 0 );
}
echo "FAILURES:\n";
foreach ( $failures as $f ) {
	echo "  - $f\n";
}
exit( 1 );
