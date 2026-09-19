<?php
/**
 * The retry sweep against real Telegram pages: a deleted post is retired, an
 * og:image cover under a foreign host is never asked to the embed page, markup
 * alarms stay out of the breaker, a requeued row is due now, two rows resolving
 * to one Catbox URL reconcile without hitting the unique index, and og covers
 * are never minted for video posts or from t.me pages.
 *
 * Needs the network: two real t.me posts are read (see NC_E2E_FIXTURE_GUIDS).
 * Run through tests/e2e/run.ps1.
 *
 * @package wp-news-collector
 */

require_once __DIR__ . '/bootstrap.php';

global $wpdb;
$p       = $wpdb->prefix;
$items   = new NC_Item_Repository();
$uploads = new NC_Catbox_Upload_Repository();
// A userhash Catbox will reject: nothing here may reach a real upload.
$syncer = new NC_Catbox_Syncer( $items, $uploads, new NC_Catbox_Uploader( 'no-account' ) );

nc_e2e_cleanup();

$gone_guid = 'https://t.me/espana_eterna/73952';
$text_guid = 'https://t.me/mi_me_con_migo2/38635';
$probe     = NC_Telegram_Media::fetch( [ 'guid' => $text_guid, 'source' => 'mi_me_con_migo2', 'telegram_id' => 38635 ] );
if ( empty( $probe['readable'] ) ) {
	echo "SKIP: t.me is not reachable from the container\n";
	exit( 0 );
}

nc_section( '1) deleted post: download_gone, retired, out of the breaker' );
$dead = 'https://cdn4.telesco.pe/file/deadphoto.jpg';
nc_e2e_item( $gone_guid, [ 'images' => [ $dead ] ] );
$id1 = nc_e2e_upload( $gone_guid, 'image', $dead, 'Download HTTP 404' );
$r   = $syncer->retry_upload( $id1, 'manual' );
nc_ok( 'outcome download_gone', ( $r['outcome'] ?? '' ) === 'download_gone' );
nc_ok( 'error is MESSAGE_GONE', ( $r['error'] ?? '' ) === NC_Telegram_Media::MESSAGE_GONE );
nc_ok( 'row retired (source_gone=1)', (int) ( nc_e2e_upload_row( $id1 )['source_gone'] ?? 0 ) === 1 );
nc_ok( 'attempt logged as download_gone', nc_e2e_last_outcome( $gone_guid ) === 'download_gone' );

nc_section( '2) retry_item retires the piece on a deleted post' );
$id1b = nc_e2e_upload( $gone_guid, 'image', $dead, 'Download HTTP 404' );
$item = $items->get_by_guid( $gone_guid );
$r    = $syncer->retry_item( (int) $item['id'] );
nc_ok( 'retry_item reports the failure', ( $r['failed'] ?? 0 ) === 1 && ( $r['results'][0]['error'] ?? '' ) === NC_Telegram_Media::MESSAGE_GONE );
nc_ok( 'retry_item marked source_gone on the piece row', (int) ( nc_e2e_upload_row( $id1b )['source_gone'] ?? 0 ) === 1 );

nc_section( '3) og cover under a foreign host is never asked to the embed page' );
$wiki = 'https://thumb.wikimedia.org/wikipedia/commons/thumb/no/such/file-e2e.jpg';
$tele = 'https://cdn4.telesco.pe/file/expired-cover.jpg';
nc_e2e_item( $text_guid, [ 'images' => [ $wiki, $tele ] ] );
$id3 = nc_e2e_upload( $text_guid, 'image', $wiki, 'Download HTTP 500' );
$r   = $syncer->retry_upload( $id3, 'manual' );
nc_ok( 'wikimedia cover is not a markup alarm (' . ( $r['error'] ?? '' ) . ')', ( $r['error'] ?? '' ) !== NC_Telegram_Media::MARKUP_ALARM );
nc_ok( 'wikimedia cover was fetched from its own url (download stage)', in_array( $r['outcome'] ?? '', [ 'download_failed', 'download_gone' ], true ) );
$id3b = nc_e2e_upload( $text_guid, 'image', $tele, 'Download HTTP 404' );
$r    = $syncer->retry_upload( $id3b, 'manual' );
nc_ok( 'telesco.pe cover on a text-only post still raises the alarm', ( $r['error'] ?? '' ) === NC_Telegram_Media::MARKUP_ALARM );

nc_section( '4) markup alarms do not trip the breaker' );
nc_e2e_park_others();
$wpdb->query( $wpdb->prepare( "UPDATE {$p}nc_catbox_uploads SET next_retry_at = NULL, retry_count = 0, source_gone = 0 WHERE id = %d", $id3b ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}nc_catbox_uploads SET next_retry_at = '2998-01-01 00:00:00' WHERE id = %d", $id3 ) );
$more = [ 'https://cdn4.telesco.pe/file/expired-1.jpg', 'https://cdn4.telesco.pe/file/expired-2.jpg' ];
$wpdb->query( $wpdb->prepare( "UPDATE {$p}nc_items SET images = %s WHERE guid = %s", wp_json_encode( array_merge( [ $wiki, $tele ], $more ) ), $text_guid ) );
foreach ( $more as $u ) {
	nc_e2e_upload( $text_guid, 'image', $u, 'Download HTTP 404' );
}
$stats = $syncer->retry_failed( 10, 8, 3 );
nc_ok( '3 alarmed pieces attempted (' . $stats['attempted'] . ')', $stats['attempted'] === 3 );
nc_ok( 'breaker not tripped by alarms', false === $stats['aborted'] );
nc_e2e_unpark_others();

nc_section( '5) requeue leaves the row due now; untouched counter' );
$old    = nc_e2e_upload( 'https://t.me/e2etmp/1', 'image', 'https://cdn4.telesco.pe/file/old.jpg', 'Download HTTP 404', [ 'uploaded_at' => '2026-09-01 00:00:00' ] );
$before = $uploads->count_sweep_untouched( 8 );
nc_ok( 'untouched counter sees a day-old never-scheduled piece (' . $before . ')', $before >= 1 );
$wpdb->query( $wpdb->prepare( "UPDATE {$p}nc_catbox_uploads SET source_gone = 1 WHERE id = %d", $old ) );
$uploads->requeue_uploads( [ $old ] );
$r5 = nc_e2e_upload_row( $old );
nc_ok( 'requeued row is due now, not NULL', null !== $r5['next_retry_at'] && abs( strtotime( $r5['next_retry_at'] . ' UTC' ) - time() ) < 60 );
nc_ok( 'requeued row leaves the untouched counter', $uploads->count_sweep_untouched( 8 ) === $before - 1 );
nc_ok( 'requeued row is retryable now', $uploads->count_retryable( gmdate( 'Y-m-d H:i:s' ), 8 ) >= 1 );

nc_section( '6) reconcile: two rows resolving to one url in the same pass' );
$g6 = 'https://t.me/e2etmp/6';
$c6 = 'https://files.catbox.moe/e2etmp6.mp4';
$v6 = 'https://cdn4.telesco.pe/file/v6.mp4';
nc_e2e_item( $g6, [ 'videos' => [ [ 'original_url' => $v6, 'catbox_url' => $c6, 'status' => 'ok' ] ] ] );
$a6     = nc_e2e_upload( $g6, 'video', $v6, 'Download HTTP 404' );
$b6     = nc_e2e_upload( $g6, 'video', $v6, 'Download HTTP 404' );
$closed = $uploads->reconcile_resolved_uploads();
$ra     = nc_e2e_upload_row( $a6 );
nc_ok( 'reconcile closed both (' . $closed . ')', 2 === $closed );
nc_ok( 'first row carries the url', is_array( $ra ) && $ra['catbox_url'] === $c6 );
nc_ok( 'second row deleted as a duplicate', null === nc_e2e_upload_row( $b6 ) );

nc_section( '7) og candidates: none for video posts, none from t.me pages' );
$proc = new NC_News_Processor( new NC_Source_Repository(), $items, new NC_Catbox_Uploader(), NC_Plugin::get_settings(), $uploads, new NC_Source_Cover_Repository() );
$m    = new ReflectionMethod( $proc, 'og_candidate_urls' );
$m->setAccessible( true );
$base = [
	'article'     => null,
	'source'      => 'c',
	'telegram_id' => 5,
	'videos'      => [],
	'text'        => 'a <a href="https://t.me/c/5?q=%23tag">#tag</a> b <a href="https://t.me/other/9">fw</a> c <a href="https://es.wikipedia.org/wiki/X">x</a>',
];
nc_ok( 't.me hrefs skipped, wikipedia kept', $m->invoke( $proc, $base ) === [ 'https://es.wikipedia.org/wiki/X' ] );
nc_ok( 'video post yields no candidates', [] === $m->invoke( $proc, array_merge( $base, [ 'videos' => [ [ 'original_url' => 'v' ] ] ] ) ) );

nc_e2e_finish();
