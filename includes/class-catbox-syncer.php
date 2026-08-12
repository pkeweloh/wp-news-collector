<?php
/**
 * Daily reconciliation: track catbox uploads from nc_items and assign to monthly albums.
 * Port of alerta-boe/app/catbox_syncer.py.
 *
 * Phase 1: scan nc_items for catbox URLs not yet in nc_catbox_uploads.
 * Phase 2: get-or-create the monthly album and call add_to_album.
 *
 * @package wp-news-collector
 */

defined( 'ABSPATH' ) || exit;

class NC_Catbox_Syncer {

	private const CATBOX_PREFIX = 'https://files.catbox.moe/';

	// Well under the hours a signed telesco.pe link lives, so a cached read can
	// never hand out a URL that expired during the sweep.
	private const MEDIA_CACHE_SECONDS = 300;

	/** @var array<string, array{0:int, 1:array<string, mixed>}> embed URL => [read at, media] */
	private array $media_cache = [];

	public function __construct(
		private NC_Item_Repository $items,
		private NC_Catbox_Upload_Repository $uploads,
		private NC_Catbox_Uploader $catbox
	) {}

	/**
	 * Read a message's embed page once per message, not once per piece: a message
	 * with video carries two pieces (the video and its poster) and would otherwise
	 * ask t.me for the very same page twice.
	 *
	 * @param array<string, mixed> $item
	 * @return array<string, mixed>
	 */
	private function message_media( array $item ): array {
		$url = NC_Telegram_Media::embed_url( $item );
		if ( '' === $url ) {
			return NC_Telegram_Media::fetch( $item );
		}
		$cached = $this->media_cache[ $url ] ?? null;
		if ( null !== $cached && ( time() - $cached[0] ) < self::MEDIA_CACHE_SECONDS ) {
			return $cached[1];
		}
		$media                    = NC_Telegram_Media::fetch( $item );
		$this->media_cache[ $url ] = [ time(), $media ];
		return $media;
	}

	/**
	 * Run both sync phases. Saves result to option nc_catbox_sync_stats.
	 *
	 * @return array{tracked:int, assigned:int, errors:string[], ran_at:string}
	 */
	public function run_sync(): array {
		$stats = [ 'tracked' => 0, 'assigned' => 0, 'errors' => [] ];
		$this->fill_missing_tracking( $stats );
		$this->assign_to_albums( $stats );
		$this->uploads->prune_attempts( NC_Catbox_Upload_Repository::ATTEMPT_RETENTION_DAYS );
		$stats['ran_at'] = gmdate( 'Y-m-d H:i:s' );
		update_option( 'nc_catbox_sync_stats', $stats );
		return $stats;
	}

	/**
	 * @param string $trigger ingest|auto_retry|manual, recorded in the attempt log.
	 * @return array<string, mixed>
	 */
	public function retry_upload( int $upload_id, string $trigger = 'manual' ): array {
		$row = $this->uploads->get_by_id( $upload_id );
		if ( null === $row ) {
			return [ 'ok' => false, 'not_found' => true, 'error' => 'Upload not found' ];
		}
		if ( '' !== (string) ( $row['catbox_url'] ?? '' ) ) {
			return [ 'ok' => true, 'already_done' => true, 'catbox_url' => (string) $row['catbox_url'] ];
		}

		$guid        = (string) ( $row['item_guid'] ?? '' );
		$upload_type = (string) ( $row['upload_type'] ?? '' );
		$original    = (string) ( $row['original_url'] ?? '' );
		$item        = $this->items->get_by_guid( $guid ) ?? [];
		$source      = $this->upload_source( $item, $upload_type, $original );

		if ( $source['markup_alarm'] ) {
			// The stale URL would 404 and retire a recoverable piece under a false cause.
			$this->uploads->set_result( $upload_id, null, NC_Telegram_Media::MARKUP_ALARM );
			$this->uploads->log_attempt( $guid, $upload_type, $original, $trigger, NC_Catbox_Uploader::OUTCOME_DOWNLOAD_FAILED, NC_Telegram_Media::MARKUP_ALARM );
			return [ 'ok' => false, 'error' => NC_Telegram_Media::MARKUP_ALARM, 'outcome' => NC_Catbox_Uploader::OUTCOME_DOWNLOAD_FAILED ];
		}

		try {
			$new_url = $this->catbox->upload_from_url( $source['url'] );
		} catch ( NC_Catbox_Exception $e ) {
			$this->uploads->set_result( $upload_id, null, $e->getMessage() );
			$outcome = NC_Catbox_Uploader::outcome_of( $e );
			$this->uploads->log_attempt( $guid, $upload_type, $original, $trigger, $outcome, $e->getMessage() );
			if ( NC_Catbox_Uploader::OUTCOME_DOWNLOAD_GONE === $outcome ) {
				$this->uploads->mark_source_gone( $upload_id );
			}
			return [ 'ok' => false, 'error' => $e->getMessage(), 'outcome' => $outcome ];
		}

		$published_at = $this->items->replace_media_url( $guid, $upload_type, $original, $new_url );
		$this->uploads->set_result( $upload_id, $new_url, null );
		$this->uploads->log_attempt( $guid, $upload_type, $original, $trigger, NC_Catbox_Uploader::OUTCOME_OK );
		// Catbox answers with the same file for identical bytes, so the repaired
		// piece may have just landed on a URL the item already holds elsewhere.
		$this->items->dedupe_poster_images( $guid );
		$album_id = $this->assign_one_to_album( $new_url, $published_at );
		return [ 'ok' => true, 'catbox_url' => $new_url, 'album_id' => $album_id ];
	}

	/**
	 * Retry failed uploads with per-row exponential backoff and a circuit-breaker.
	 * Orphaned failures (piece gone from the item) are parked rather than retried.
	 * A 404/410 source is retired and kept out of the breaker: counting dead links
	 * would abort every sweep and starve the pieces that are still recoverable.
	 *
	 * @return array{attempted:int, succeeded:int, failed:int, gone:int, orphaned:int, reconciled:int, deduped:int, aborted:bool, remaining:int, ran_at:string}
	 */
	public function retry_failed(
		int $batch_size = 10,
		int $max_attempts = 8,
		int $breaker_threshold = 3,
		int $backoff_base = 600,
		int $backoff_cap = 21600
	): array {
		$stats = [ 'attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'gone' => 0, 'orphaned' => 0, 'aborted' => false ];
		$batch_size = max( 1, $batch_size );
		// Each sweep re-reads the embed pages: a link signed on the previous run
		// may already have expired.
		$this->media_cache = [];

		// Both tidy stored state by looking at the whole item, which a piece-by-piece
		// retry never does. Reconciling runs before anything else in the sweep: past
		// this point park_orphan() would file a resolved piece as an orphan.
		$stats['reconciled'] = $this->uploads->reconcile_resolved_uploads();
		$stats['deduped']    = $this->items->dedupe_poster_images();

		// Over-fetch: the linked filter runs in PHP, so orphans must not eat the batch.
		$candidates  = $this->uploads->get_retryable_uploads( gmdate( 'Y-m-d H:i:s' ), $max_attempts, max( $batch_size * 5, 100 ) );
		$consecutive = 0;

		foreach ( $candidates as $row ) {
			if ( $stats['attempted'] >= $batch_size ) {
				break;
			}
			$id       = (int) ( $row['id'] ?? 0 );
			$guid     = (string) ( $row['item_guid'] ?? '' );
			$type     = (string) ( $row['upload_type'] ?? '' );
			$original = (string) ( $row['original_url'] ?? '' );

			if ( ! $this->is_piece_linked( $guid, $type, $original ) ) {
				// Park it: a NULL next_retry_at would keep it at the queue head forever.
				$this->uploads->park_orphan( $id );
				$stats['orphaned']++;
				continue;
			}

			$stats['attempted']++;
			$result = $this->retry_upload( $id, 'auto_retry' );
			if ( ! empty( $result['ok'] ) ) {
				$stats['succeeded']++;
				$consecutive = 0;
				continue;
			}

			// Already retired by retry_upload: no backoff, and out of the breaker.
			if ( NC_Catbox_Uploader::OUTCOME_DOWNLOAD_GONE === ( $result['outcome'] ?? '' ) ) {
				$stats['gone']++;
				continue;
			}

			$stats['failed']++;
			$retry_count = (int) ( $row['retry_count'] ?? 0 );
			$delay       = (int) min( $backoff_cap, $backoff_base * ( 2 ** $retry_count ) );
			$this->uploads->schedule_upload_retry( $id, gmdate( 'Y-m-d H:i:s', time() + $delay ) );
			$consecutive++;
			if ( $breaker_threshold > 0 && $consecutive >= $breaker_threshold ) {
				$stats['aborted'] = true;
				break;
			}
		}

		$stats['remaining'] = $this->uploads->count_retryable( gmdate( 'Y-m-d H:i:s' ), $max_attempts );
		$stats['ran_at']    = gmdate( 'Y-m-d H:i:s' );
		update_option( 'nc_catbox_retry_stats', $stats );
		return $stats;
	}

	/**
	 * Remove orphaned nc_catbox_uploads rows: those whose media (catbox_url or
	 * original_url) is no longer referenced by any live item. These accrue from
	 * items that never inserted, or media edited/removed later, and they clutter
	 * the log and inflate retry/stats counts (they can also starve the retry
	 * queue head). Deletes ONLY the log row: the Catbox file is never touched,
	 * because the account is shared; legit tracking self-heals via the sync
	 * phase-1 re-tracking. $delete=false is a dry-run that only reports. Single
	 * in-memory scan, O(items + uploads). Result saved to nc_catbox_cleanup_stats.
	 *
	 * @return array{total:int, referenced:int, orphans:int, dead_guid:int, failed:int, deleted:int, dry_run:bool, ran_at:string}
	 */
	public function cleanup_orphans( bool $delete = false ): array {
		$referenced = $this->items->get_referenced_media_urls();
		$live_guids = $this->items->get_all_guids();
		$rows       = $this->uploads->get_all_for_orphan_scan();

		$orphan_ids = [];
		$dead_guid  = 0;
		$failed     = 0;
		foreach ( $rows as $row ) {
			$catbox   = (string) ( $row['catbox_url'] ?? '' );
			$original = (string) ( $row['original_url'] ?? '' );
			// No item carries this guid, so the row cannot describe a piece of any
			// live item, whatever its URL says. That happens when the same message
			// was ingested under both guid spellings (t.me and telegram.me): the
			// leftovers point at a URL the surviving item legitimately uses, and a
			// scan by URL alone would protect them for good.
			$dead = ! isset( $live_guids[ (string) ( $row['item_guid'] ?? '' ) ] );
			if ( ! $dead
				&& ( ( '' !== $catbox && isset( $referenced[ $catbox ] ) )
					|| ( '' !== $original && isset( $referenced[ $original ] ) ) ) ) {
				continue;
			}
			$orphan_ids[] = (int) ( $row['id'] ?? 0 );
			if ( $dead ) {
				$dead_guid++;
			}
			if ( '' !== (string) ( $row['error'] ?? '' ) ) {
				$failed++;
			}
		}

		$deleted = ( $delete && ! empty( $orphan_ids ) ) ? $this->uploads->delete_by_ids( $orphan_ids ) : 0;

		$stats = [
			'total'      => count( $rows ),
			'referenced' => count( $referenced ),
			'orphans'    => count( $orphan_ids ),
			'dead_guid'  => $dead_guid,
			'failed'     => $failed,
			'deleted'    => $deleted,
			'dry_run'    => ! $delete,
			'ran_at'     => gmdate( 'Y-m-d H:i:s' ),
		];
		update_option( 'nc_catbox_cleanup_stats', $stats );
		return $stats;
	}

	/**
	 * Put retired pieces back in the queue with their counters reset.
	 *
	 * `source_gone` was set when a dead link was the end of the story. Now that a
	 * Telegram link is re-minted from the message's embed page, a piece is only
	 * gone once the fresh page fails too. A button and not a migration: it re-runs
	 * the whole recovery, which is a person's call. Only pieces still linked to a
	 * live item come back; an orphan would just clog the queue again.
	 *
	 * @return int Rows requeued.
	 */
	public function requeue_expired_sources(): int {
		$rows = $this->uploads->get_source_gone_rows();
		if ( empty( $rows ) ) {
			return 0;
		}
		$linked = $this->linked_map( $rows );
		$ids    = [];
		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( ! empty( $linked[ $id ] ) ) {
				$ids[] = $id;
			}
		}
		return $this->uploads->requeue_uploads( $ids );
	}

	/**
	 * Which upload rows are still linked to a live piece, keyed by upload id.
	 * Grouped by guid so it costs one item fetch per distinct item.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, bool>
	 */
	public function linked_map( array $rows ): array {
		$items  = [];
		$linked = [];
		foreach ( $rows as $row ) {
			$id       = (int) ( $row['id'] ?? 0 );
			$guid     = (string) ( $row['item_guid'] ?? '' );
			$type     = (string) ( $row['upload_type'] ?? '' );
			$original = (string) ( $row['original_url'] ?? '' );
			if ( '' === $guid || '' === $original ) {
				$linked[ $id ] = false;
				continue;
			}
			if ( ! array_key_exists( $guid, $items ) ) {
				$items[ $guid ] = $this->items->get_by_guid( $guid );
			}
			$item = $items[ $guid ];
			$hit  = false;
			foreach ( is_array( $item ) ? $this->pending_pieces( $item ) : [] as [ $ptype, $purl ] ) {
				if ( $ptype === $type && $purl === $original ) {
					$hit = true;
					break;
				}
			}
			$linked[ $id ] = $hit;
		}
		return $linked;
	}

	/** Whether (upload_type, original_url) is still a live, un-uploaded piece of its item. */
	private function is_piece_linked( string $guid, string $upload_type, string $original_url ): bool {
		if ( '' === $guid || '' === $original_url ) {
			return false;
		}
		$item = $this->items->get_by_guid( $guid );
		if ( null === $item ) {
			return false;
		}
		foreach ( $this->pending_pieces( $item ) as [ $ptype, $purl ] ) {
			if ( $ptype === $upload_type && $purl === $original_url ) {
				return true;
			}
		}
		return false;
	}

	/** @return array<string, mixed> */
	public function retry_item( int $item_id ): array {
		$item = $this->items->get_by_id( $item_id );
		if ( null === $item ) {
			return [ 'ok' => false, 'not_found' => true ];
		}
		$guid        = (string) ( $item['guid'] ?? '' );
		$source      = (string) ( $item['source'] ?? '' );
		$source_name = (string) ( $item['source_name'] ?? '' );

		$results = [];
		foreach ( $this->pending_pieces( $item ) as [ $upload_type, $original ] ) {
			$src = $this->upload_source( $item, $upload_type, $original );
			if ( $src['markup_alarm'] ) {
				$this->uploads->log_attempt( $guid, $upload_type, $original, 'manual', NC_Catbox_Uploader::OUTCOME_DOWNLOAD_FAILED, NC_Telegram_Media::MARKUP_ALARM );
				$results[] = [ 'type' => $upload_type, 'error' => NC_Telegram_Media::MARKUP_ALARM ];
				continue;
			}
			try {
				$new_url = $this->catbox->upload_from_url( $src['url'] );
			} catch ( NC_Catbox_Exception $e ) {
				$this->uploads->resolve_result( $source, $source_name, $guid, $upload_type, $original, null, $e->getMessage() );
				$this->uploads->log_attempt( $guid, $upload_type, $original, 'manual', NC_Catbox_Uploader::outcome_of( $e ), $e->getMessage() );
				$results[] = [ 'type' => $upload_type, 'error' => $e->getMessage() ];
				continue;
			}
			$this->items->replace_media_url( $guid, $upload_type, $original, $new_url );
			$this->uploads->resolve_result( $source, $source_name, $guid, $upload_type, $original, $new_url, null );
			$this->uploads->log_attempt( $guid, $upload_type, $original, 'manual', NC_Catbox_Uploader::OUTCOME_OK );
			$this->assign_one_to_album( $new_url, (string) ( $item['published_at'] ?? '' ) );
			$results[] = [ 'type' => $upload_type, 'catbox_url' => $new_url ];
		}

		// Same collision as in retry_upload, now for the whole item at once.
		$this->items->dedupe_poster_images( $guid );

		$failed = 0;
		foreach ( $results as $r ) {
			if ( isset( $r['error'] ) ) {
				$failed++;
			}
		}
		return [
			'ok'       => 0 === $failed,
			'pending'  => count( $results ),
			'uploaded' => count( $results ) - $failed,
			'failed'   => $failed,
			'results'  => $results,
		];
	}

	private function assign_one_to_album( string $catbox_url, ?string $published_at ): ?string {
		$month = substr( (string) $published_at, 0, 7 );
		if ( '' === $month || strlen( $month ) !== 7 ) {
			return null;
		}
		$album_id = $this->uploads->get_album_for_month( $month );
		if ( '' === $album_id ) {
			try {
				$album_id = $this->catbox->create_album( NC_Plugin::catbox_album_name( $month ) );
				$this->uploads->save_album_for_month( $month, $album_id );
			} catch ( NC_Catbox_Exception $e ) {
				return null;
			}
		}
		try {
			$this->catbox->add_to_album( $album_id, [ basename( $catbox_url ) ] );
			$this->uploads->set_album( $catbox_url, $album_id );
		} catch ( NC_Catbox_Exception $e ) {
			return $album_id;
		}
		return $album_id;
	}

	/**
	 * Where to fetch a piece from now, re-derived rather than trusted: an article
	 * cover moves and a Telegram link expires in hours, so the stored URL is
	 * usually dead. `markup_alarm` means the embed showed the message but gave up
	 * no media: a broken parser, not a piece to retry.
	 *
	 * @param array<string, mixed> $item
	 * @return array{url:string, markup_alarm:bool}
	 */
	private function upload_source( array $item, string $upload_type, string $original_url ): array {
		if ( 'article_image' === $upload_type ) {
			$article = is_array( $item['article'] ?? null ) ? $item['article'] : [];
			$url     = (string) ( $article['url'] ?? '' );
			if ( '' !== $url ) {
				$fresh = NC_OG_Scraper::fetch( $url )['image'];
				if ( '' !== $fresh ) {
					return [ 'url' => $fresh, 'markup_alarm' => false ];
				}
				// og failed (e.g. YouTube consent page): derive from the video id.
				$yt = NC_Feed_Parser::extract_youtube_id( $url );
				if ( '' !== $yt ) {
					return [ 'url' => NC_OG_Scraper::youtube_thumbnail( $yt ), 'markup_alarm' => false ];
				}
			}
			$ids = (array) ( $item['youtube_ids'] ?? [] );
			if ( ! empty( $ids ) ) {
				return [ 'url' => NC_OG_Scraper::youtube_thumbnail( (string) $ids[0] ), 'markup_alarm' => false ];
			}
		} elseif ( in_array( $upload_type, [ 'image', 'poster', 'video' ], true ) ) {
			$media = $this->message_media( $item );
			$fresh = NC_Telegram_Media::fresh_url_for( $item, $upload_type, $original_url, $media );
			if ( '' !== $fresh ) {
				return [ 'url' => $fresh, 'markup_alarm' => false ];
			}
			if ( NC_Telegram_Media::markup_suspect( $media ) ) {
				return [ 'url' => $original_url, 'markup_alarm' => true ];
			}
		}
		return [ 'url' => $original_url, 'markup_alarm' => false ];
	}

	/**
	 * @param array<string, mixed> $item
	 * @return list<array{0:string, 1:string}>  [[upload_type, original_url], ...]
	 */
	private function pending_pieces( array $item ): array {
		$pieces = [];
		foreach ( (array) ( $item['images'] ?? [] ) as $url ) {
			if ( $this->is_original( (string) $url ) ) {
				$pieces[] = [ 'image', (string) $url ];
			}
		}
		foreach ( (array) ( $item['videos'] ?? [] ) as $v ) {
			$v = (array) $v;
			if ( $this->is_original( (string) ( $v['poster_url'] ?? '' ) ) ) {
				$pieces[] = [ 'poster', (string) $v['poster_url'] ];
			}
			// Skip too_big videos: they are intentionally not uploaded.
			if ( ( $v['status'] ?? '' ) !== 'too_big'
				&& '' !== (string) ( $v['original_url'] ?? '' )
				&& ! $this->is_catbox( (string) ( $v['catbox_url'] ?? '' ) ) ) {
				$pieces[] = [ 'video', (string) $v['original_url'] ];
			}
		}
		foreach ( (array) ( $item['audios'] ?? [] ) as $a ) {
			$a = (array) $a;
			if ( '' !== (string) ( $a['original_url'] ?? '' )
				&& ! $this->is_catbox( (string) ( $a['catbox_url'] ?? '' ) ) ) {
				$pieces[] = [ 'audio', (string) $a['original_url'] ];
			}
		}
		$article = is_array( $item['article'] ?? null ) ? $item['article'] : null;
		if ( is_array( $article ) && $this->is_original( (string) ( $article['image_url'] ?? '' ) ) ) {
			$pieces[] = [ 'article_image', (string) $article['image_url'] ];
		}
		return $pieces;
	}

	private function is_original( string $url ): bool {
		return '' !== $url && 0 === strpos( $url, 'http' ) && ! $this->is_catbox( $url );
	}

	// Phase 1

	/**
	 * @param array{tracked:int, assigned:int, errors:string[]} $stats
	 */
	private function fill_missing_tracking( array &$stats ): void {
		foreach ( $this->items->get_all_ids() as $id ) {
			$item = $this->items->get_by_id( $id );
			if ( null === $item ) {
				continue;
			}
			$uploaded_at = (string) ( $item['fetched_at'] ?? gmdate( 'Y-m-d H:i:s' ) );
			foreach ( $this->extract_catbox_urls( $item ) as [ $catbox_url, $upload_type ] ) {
				$inserted = $this->uploads->insert_if_missing(
					(string) ( $item['source'] ?? '' ),
					(string) ( $item['source_name'] ?? '' ),
					(string) ( $item['guid'] ?? '' ),
					$upload_type,
					$catbox_url,
					$uploaded_at
				);
				if ( $inserted ) {
					$stats['tracked']++;
				}
			}
		}
	}

	// Phase 2

	/**
	 * @param array{tracked:int, assigned:int, errors:string[]} $stats
	 */
	private function assign_to_albums( array &$stats ): void {
		$unassigned = $this->uploads->get_unassigned_by_month();
		if ( empty( $unassigned ) ) {
			return;
		}

		// Group by month of published_at.
		$by_month = [];
		foreach ( $unassigned as $row ) {
			$month = substr( (string) ( $row['published_at'] ?? '' ), 0, 7 );
			if ( '' === $month || strlen( $month ) !== 7 ) {
				continue;
			}
			$by_month[ $month ][] = $row;
		}

		foreach ( $by_month as $month => $rows ) {
			$album_id = $this->uploads->get_album_for_month( $month );
			if ( '' === $album_id ) {
				try {
					$album_name = NC_Plugin::catbox_album_name( $month );
					$album_id   = $this->catbox->create_album( $album_name );
					$this->uploads->save_album_for_month( $month, $album_id );
				} catch ( NC_Catbox_Exception $e ) {
					$stats['errors'][] = sprintf( 'Album creation failed (%s): %s', $month, $e->getMessage() );
					continue;
				}
			}

			// Deduplicate filenames for this batch.
			$filenames = [];
			$seen      = [];
			foreach ( $rows as $row ) {
				$filename = basename( (string) $row['catbox_url'] );
				if ( '' !== $filename && ! isset( $seen[ $filename ] ) ) {
					$filenames[]          = $filename;
					$seen[ $filename ]    = true;
				}
			}

			if ( empty( $filenames ) ) {
				continue;
			}

			try {
				$this->catbox->add_to_album( $album_id, $filenames );
				foreach ( $rows as $row ) {
					$this->uploads->set_album( (string) $row['catbox_url'], $album_id );
					$stats['assigned']++;
				}
			} catch ( NC_Catbox_Exception $e ) {
				$stats['errors'][] = sprintf( 'Album add failed (%s/%s): %s', $month, $album_id, $e->getMessage() );
			}
		}
	}

	// Helpers

	/**
	 * Extract all catbox URLs from a decoded item row with their upload type.
	 *
	 * @param array<string, mixed> $item
	 * @return list<array{0:string, 1:string}>  [[catbox_url, upload_type], ...]
	 */
	private function extract_catbox_urls( array $item ): array {
		$results = [];

		foreach ( (array) ( $item['images'] ?? [] ) as $url ) {
			if ( $this->is_catbox( (string) $url ) ) {
				$results[] = [ (string) $url, 'image' ];
			}
		}

		foreach ( (array) ( $item['videos'] ?? [] ) as $v ) {
			$v = (array) $v;
			if ( $this->is_catbox( (string) ( $v['poster_url'] ?? '' ) ) ) {
				$results[] = [ (string) $v['poster_url'], 'poster' ];
			}
			if ( $this->is_catbox( (string) ( $v['catbox_url'] ?? '' ) ) ) {
				$results[] = [ (string) $v['catbox_url'], 'video' ];
			}
		}

		foreach ( (array) ( $item['audios'] ?? [] ) as $a ) {
			$a = (array) $a;
			if ( $this->is_catbox( (string) ( $a['catbox_url'] ?? '' ) ) ) {
				$results[] = [ (string) $a['catbox_url'], 'audio' ];
			}
		}

		$article = is_array( $item['article'] ?? null ) ? $item['article'] : null;
		if ( is_array( $article ) && $this->is_catbox( (string) ( $article['image_url'] ?? '' ) ) ) {
			$results[] = [ (string) $article['image_url'], 'article_image' ];
		}

		return $results;
	}

	private function is_catbox( string $url ): bool {
		return '' !== $url && 0 === strpos( $url, self::CATBOX_PREFIX );
	}
}
