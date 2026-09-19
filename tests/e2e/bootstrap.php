<?php
/**
 * Shared helpers for the database-backed tests under tests/e2e/.
 *
 * These run inside the docker WordPress through `wp eval-file` (see run.ps1),
 * so the plugin is loaded and $wpdb points at the dev database. Every fixture
 * is tagged with the `e2etmp` source so a crashed run can be cleaned by the
 * next one, and real Telegram posts used as fixtures are listed in FIXTURE_GUIDS.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

const NC_E2E_SOURCE = 'e2etmp';

/** Real t.me posts a test may insert as items: deleted, text-only, and so on. */
const NC_E2E_FIXTURE_GUIDS = [
	'https://t.me/espana_eterna/73952',    // deleted: t.me answers "Post not found"
	'https://t.me/mi_me_con_migo2/38635',  // long text, no media, forwarded from 37342
];

$nc_e2e_failures = [];
$nc_e2e_passed   = 0;

function nc_ok( string $label, bool $cond ): void {
	global $nc_e2e_failures, $nc_e2e_passed;
	echo ( $cond ? '  ok: ' : '  FAIL: ' ) . $label . "\n";
	if ( $cond ) {
		$nc_e2e_passed++;
	} else {
		$nc_e2e_failures[] = $label;
	}
}

function nc_section( string $title ): void {
	echo $title . "\n";
}

/** Remove every fixture row this suite may have left, in all three tables. */
function nc_e2e_cleanup(): void {
	global $wpdb;
	$p     = $wpdb->prefix;
	$guids = implode( ',', array_map( fn( $g ) => "'" . esc_sql( $g ) . "'", NC_E2E_FIXTURE_GUIDS ) );
	$like  = "'https://t.me/" . NC_E2E_SOURCE . "/%'";
	$wpdb->query( "DELETE FROM {$p}nc_items WHERE guid LIKE {$like} OR guid IN ({$guids})" );
	$wpdb->query( "DELETE FROM {$p}nc_catbox_uploads WHERE item_guid LIKE {$like} OR item_guid IN ({$guids})" );
	$wpdb->query( "DELETE FROM {$p}nc_catbox_upload_attempts WHERE item_guid LIKE {$like} OR item_guid IN ({$guids})" );
}

/** A minimal nc_items row; $fields overrides any column insert() accepts. */
function nc_e2e_item( string $guid, array $fields = [] ): array {
	$items = new NC_Item_Repository();
	$parts = explode( '/', $guid );
	$items->insert(
		array_merge(
			[
				'guid'         => $guid,
				'telegram_id'  => (int) end( $parts ),
				'source'       => $parts[ count( $parts ) - 2 ] ?? NC_E2E_SOURCE,
				'source_name'  => NC_E2E_SOURCE,
				'text'         => 'e2e fixture',
				'published_at' => '2026-09-01 00:00:00',
			],
			$fields
		)
	);
	return $items->get_by_guid( $guid ) ?? [];
}

/** A failed nc_catbox_uploads row, returned by id; $extra overrides any column. */
function nc_e2e_upload( string $guid, string $type, string $original, string $error, array $extra = [] ): int {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert(
		$wpdb->prefix . 'nc_catbox_uploads',
		array_merge(
			[
				'source'       => NC_E2E_SOURCE,
				'source_name'  => NC_E2E_SOURCE,
				'item_guid'    => $guid,
				'upload_type'  => $type,
				'original_url' => $original,
				'catbox_url'   => null,
				'error'        => $error,
				'uploaded_at'  => $now,
				'created_at'   => $now,
			],
			$extra
		)
	);
	return (int) $wpdb->insert_id;
}

/** @return array<string, mixed>|null */
function nc_e2e_upload_row( int $id ): ?array {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}nc_catbox_uploads WHERE id = %d", $id ), ARRAY_A );
	return is_array( $row ) ? $row : null;
}

/** Last attempt-log outcome for a guid, or '' when none. */
function nc_e2e_last_outcome( string $guid ): string {
	global $wpdb;
	return (string) $wpdb->get_var(
		$wpdb->prepare( "SELECT outcome FROM {$wpdb->prefix}nc_catbox_upload_attempts WHERE item_guid = %s ORDER BY id DESC LIMIT 1", $guid )
	);
}

/**
 * Hide every other retryable row from the sweep for the duration of a test,
 * so retry_failed() sees only the fixtures. Undone by nc_e2e_unpark_others().
 */
function nc_e2e_park_others(): void {
	global $wpdb;
	$wpdb->query(
		"UPDATE {$wpdb->prefix}nc_catbox_uploads SET next_retry_at = '2998-01-01 00:00:00'
		 WHERE error IS NOT NULL AND ( catbox_url IS NULL OR catbox_url = '' ) AND source_gone = 0
		   AND ( next_retry_at IS NULL OR next_retry_at < '2998-01-01' )
		   AND source <> '" . NC_E2E_SOURCE . "'"
	);
}

function nc_e2e_unpark_others(): void {
	global $wpdb;
	$wpdb->query( "UPDATE {$wpdb->prefix}nc_catbox_uploads SET next_retry_at = NULL WHERE next_retry_at = '2998-01-01 00:00:00'" );
}

/** Print the tally and exit non-zero on any failure, so run.ps1 can aggregate. */
function nc_e2e_finish(): void {
	global $nc_e2e_failures, $nc_e2e_passed;
	nc_e2e_cleanup();
	echo "\n";
	if ( empty( $nc_e2e_failures ) ) {
		echo "ALL PASS ({$nc_e2e_passed})\n";
		exit( 0 );
	}
	echo count( $nc_e2e_failures ) . " FAILURES:\n";
	foreach ( $nc_e2e_failures as $f ) {
		echo "  - $f\n";
	}
	exit( 1 );
}
