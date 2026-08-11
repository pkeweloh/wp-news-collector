<?php
/**
 * Standalone test for the Telegram embed reader: runs without WordPress.
 * Usage: php tests/test-telegram-media.php
 *
 * The markup below is trimmed from real t.me embed responses, keeping the parts
 * the parser keys on. No test touches the network: only parse()/fresh_url_for()/
 * embed_url() are exercised, and a non-t.me guid yields no address at all.
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../includes/class-telegram-media.php';

$failures = [];

function assert_eq( string $label, $actual, $expected, array &$failures ): void {
	if ( $actual !== $expected ) {
		$failures[] = $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true );
	} else {
		echo "  ok: $label\n";
	}
}

$photo_html = <<<'HTML'
<div class="tgme_widget_message" data-post="canal/71625">
  <div class="tgme_widget_message_user">
    <a href="https://t.me/canal">
      <i class="tgme_widget_message_user_photo bgcolor2"
         style="background-image:url('https://cdn4.telesco.pe/file/AVATAR')"></i>
    </a>
  </div>
  <div class="tgme_widget_message_bubble">
    <a class="tgme_widget_message_photo_wrap"
       style="width:468px;background-image:url('https://cdn4.telesco.pe/file/FOTO1')"></a>
    <a class="tgme_widget_message_photo_wrap"
       style="width:468px;background-image:url('https://cdn4.telesco.pe/file/FOTO2')"></a>
  </div>
</div>
HTML;

$video_html = <<<'HTML'
<div class="tgme_widget_message" data-post="canal/71623">
  <div class="tgme_widget_message_user">
    <i class="tgme_widget_message_user_photo bgcolor2"
       style="background-image:url('https://cdn4.telesco.pe/file/AVATAR')"></i>
  </div>
  <div class="tgme_widget_message_bubble">
    <div class="tgme_widget_message_video_player js-message_video_player">
      <i class="tgme_widget_message_video_thumb"
         style="background-image:url('https://cdn4.telesco.pe/file/POSTER')"></i>
      <video src="https://cdn4.telesco.pe/file/video.mp4?token=abc"
             class="tgme_widget_message_video js-message_video"></video>
    </div>
  </div>
</div>
HTML;

$text_only_html = <<<'HTML'
<div class="tgme_widget_message" data-post="canal/71624">
  <div class="tgme_widget_message_bubble">
    <div class="tgme_widget_message_text js-message_text">Solo texto.</div>
  </div>
</div>
HTML;

function nc_test_item( array $overrides = [] ): array {
	return array_merge(
		[
			'guid'        => 'https://t.me/canal/71625',
			'source'      => 'canal',
			'telegram_id' => 71625,
			'images'      => [],
			'videos'      => [],
		],
		$overrides
	);
}

// Parsing

$photo_media = NC_Telegram_Media::parse( $photo_html );
assert_eq(
	'photos in page order',
	$photo_media['photos'],
	[ 'https://cdn4.telesco.pe/file/FOTO1', 'https://cdn4.telesco.pe/file/FOTO2' ],
	$failures
);

$video_media = NC_Telegram_Media::parse( $video_html );
assert_eq(
	'video with its poster',
	$video_media['videos'],
	[ [ 'https://cdn4.telesco.pe/file/video.mp4?token=abc', 'https://cdn4.telesco.pe/file/POSTER' ] ],
	$failures
);

// The avatar sits outside the media wrappers, so reading only from them keeps the
// channel logo structurally out.
$everything = $photo_media['photos'];
foreach ( $video_media['videos'] as $pair ) {
	$everything = array_merge( $everything, $pair );
}
$has_avatar = false;
foreach ( $everything as $url ) {
	$has_avatar = $has_avatar || false !== strpos( $url, 'AVATAR' );
}
assert_eq( 'never takes the channel avatar', $has_avatar, false, $failures );

// A page that loaded and shows the message but yields nothing is a broken parser.
$text_media = NC_Telegram_Media::parse( $text_only_html );
assert_eq( 'text-only message is suspect', NC_Telegram_Media::markup_suspect( $text_media ), true, $failures );

// An error page says nothing about our selectors.
$no_message = NC_Telegram_Media::parse( '<html><body>Post not found</body></html>' );
assert_eq( 'page without a message has_message', $no_message['has_message'], false, $failures );
assert_eq( 'page without a message is not an alarm', NC_Telegram_Media::markup_suspect( $no_message ), false, $failures );

// Matching by position

$item = nc_test_item( [ 'images' => [ 'https://cdn/dead1.jpg', 'https://cdn/dead2.jpg' ] ] );
assert_eq(
	'image matched by position',
	NC_Telegram_Media::fresh_url_for( $item, 'image', 'https://cdn/dead2.jpg', $photo_media ),
	'https://cdn4.telesco.pe/file/FOTO2',
	$failures
);

// A repaired piece keeps its slot with a Catbox URL in it.
$partial = nc_test_item( [ 'images' => [ 'https://files.catbox.moe/done.jpg', 'https://cdn/dead2.jpg' ] ] );
assert_eq(
	'position holds after a partial recovery',
	NC_Telegram_Media::fresh_url_for( $partial, 'image', 'https://cdn/dead2.jpg', $photo_media ),
	'https://cdn4.telesco.pe/file/FOTO2',
	$failures
);

$with_video = nc_test_item( [ 'videos' => [ [ 'original_url' => 'https://cdn/dead.mp4', 'poster_url' => 'https://cdn/dead.jpg' ] ] ] );
assert_eq(
	'video matched',
	NC_Telegram_Media::fresh_url_for( $with_video, 'video', 'https://cdn/dead.mp4', $video_media ),
	'https://cdn4.telesco.pe/file/video.mp4?token=abc',
	$failures
);
assert_eq(
	'poster matched',
	NC_Telegram_Media::fresh_url_for( $with_video, 'poster', 'https://cdn/dead.jpg', $video_media ),
	'https://cdn4.telesco.pe/file/POSTER',
	$failures
);

// A wrong URL would be worse than none.
$mismatch = nc_test_item( [ 'images' => [ 'https://cdn/dead.jpg' ] ] );
assert_eq(
	'unmatched piece rather than a guess',
	NC_Telegram_Media::fresh_url_for( $mismatch, 'image', 'https://cdn/dead.jpg', $video_media ),
	'',
	$failures
);

// Article covers come from the cited page, not from the message.
assert_eq(
	'article cover is not a telegram piece',
	NC_Telegram_Media::fresh_url_for( $mismatch, 'article_image', 'https://cdn/dead.jpg', $photo_media ),
	'',
	$failures
);

// Addressing

assert_eq(
	'embed url from source and message id',
	NC_Telegram_Media::embed_url( nc_test_item( [ 'guid' => 'https://t.me/otro/1' ] ) ),
	'https://t.me/canal/71625?embed=1',
	$failures
);
assert_eq(
	'falls back to the guid',
	NC_Telegram_Media::embed_url( [ 'guid' => 'https://t.me/canal/999', 'source' => '', 'telegram_id' => 0 ] ),
	'https://t.me/canal/999?embed=1',
	$failures
);
// A source and an id alone must not conjure a t.me address.
assert_eq(
	'non-telegram item has no embed',
	NC_Telegram_Media::embed_url( [ 'guid' => 'https://example.com/post/1', 'source' => 'canal', 'telegram_id' => 5 ] ),
	'',
	$failures
);

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
