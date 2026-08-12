<?php
/**
 * Standalone test for the duplicated-poster defense: runs without WordPress.
 * Usage: php tests/test-catbox-dedupe.php
 *
 * The case that matters is an image and a video poster sharing ONE Catbox URL,
 * which is what the service's dedup by content produces: the photo of a message
 * and the video thumbnail are the same bytes and come back as the same file.
 * With different URLs nothing is reproduced.
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../includes/class-item-repository.php';
require_once __DIR__ . '/../includes/class-template-helpers.php';

$failures = [];

function assert_eq( string $label, $actual, $expected, array &$failures ): void {
	if ( $actual !== $expected ) {
		$failures[] = $label . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true );
	} else {
		echo "  ok: $label\n";
	}
}

const CATBOX_FRAME = 'https://files.catbox.moe/frame.jpg';
const CATBOX_VIDEO = 'https://files.catbox.moe/clip.mp4';
const CATBOX_OTHER = 'https://files.catbox.moe/other.jpg';

// Stripping

assert_eq(
	'the image that repeats a poster goes',
	NC_Item_Repository::strip_poster_images(
		[ CATBOX_FRAME ],
		[ [ 'poster_url' => CATBOX_FRAME, 'catbox_url' => CATBOX_VIDEO ] ]
	),
	[],
	$failures
);

assert_eq(
	'unrelated images are kept',
	NC_Item_Repository::strip_poster_images(
		[ CATBOX_OTHER, CATBOX_FRAME ],
		[ [ 'poster_url' => CATBOX_FRAME ] ]
	),
	[ CATBOX_OTHER ],
	$failures
);

// Before uploading, the URLs are Telegram's: same match, one upload saved.
assert_eq(
	'works on original URLs too',
	NC_Item_Repository::strip_poster_images(
		[ 'https://cdn4.telesco.pe/file/FRAME' ],
		[ [ 'poster_url' => 'https://cdn4.telesco.pe/file/FRAME', 'original_url' => 'https://cdn4.telesco.pe/file/v.mp4' ] ]
	),
	[],
	$failures
);

assert_eq(
	'a video without poster removes nothing',
	NC_Item_Repository::strip_poster_images( [ CATBOX_OTHER ], [ [ 'original_url' => CATBOX_VIDEO ] ] ),
	[ CATBOX_OTHER ],
	$failures
);

// Rendering: the view must be right before any sweep touches the stored copy.

$item = [
	'images'      => [ CATBOX_FRAME, CATBOX_OTHER ],
	'videos'      => [ [ 'poster_url' => CATBOX_FRAME, 'catbox_url' => CATBOX_VIDEO, 'status' => 'ok' ] ],
	'article'     => null,
	'youtube_ids' => [],
];
$media = NC_Template_Helpers::build_media_list( $item );

assert_eq(
	'the deck is the video plus the unrelated image',
	$media,
	[
		[ 'kind' => 'video', 'src' => CATBOX_VIDEO, 'poster' => CATBOX_FRAME ],
		[ 'kind' => 'image', 'src' => CATBOX_OTHER ],
	],
	$failures
);

// A too_big video is still handled by extract_too_big_videos(), and its poster must
// not come back as a loose image through the deck either.
$too_big = [
	'images'      => [ CATBOX_FRAME ],
	'videos'      => [ [ 'poster_url' => CATBOX_FRAME, 'status' => 'too_big' ] ],
	'article'     => null,
	'youtube_ids' => [],
];
assert_eq( 'a too_big poster is not painted twice', NC_Template_Helpers::build_media_list( $too_big ), [], $failures );
assert_eq(
	'and it is still offered as a Telegram link',
	NC_Template_Helpers::extract_too_big_videos( $too_big ),
	[ [ 'poster' => CATBOX_FRAME ] ],
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
