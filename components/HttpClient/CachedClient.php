<?php

namespace WordPress\HttpClient;

use SplQueue;
use SplFileObject;
use WordPress\ByteStream\WriteStream\ByteWriteStream;
use WordPress\ByteStream\WriteStream\FileWriteStream;

/**
 * Decorator that adds on‑disk HTTP caching to an existing asynchronous Client.
 *
 *  • Fresh GETs are answered from the cache via synthetic events (HEADERS → many
 *    BODY_CHUNKs → FINISHED), streaming the body from disk in configurable
 *    chunks so the whole payload never resides in memory at once.
 *  • Stale hits trigger a *conditional HEAD* first.  If the server answers
 *    304 Not Modified (or returns matching ETag/Last‑Modified), the cached body
 *    is replayed.  Otherwise we follow up with an unconditional GET to refresh
 *    the cache.
 *  • Cache‑able responses are streamed straight to disk while they download and
 *    committed once finished.  Mutating requests invalidate the stored
 *    representation immediately.
 *
 *  No code here compares timestamps; freshness is entirely delegated to the
 *  injected CachePolicy implementation.
 */
final class CachedClient {

    /* --------------------------------------------------------------------- */
    /*  configuration                                                        */
    /* --------------------------------------------------------------------- */

    public const DEFAULT_CHUNK_SIZE = 8192;

    /* --------------------------------------------------------------------- */
    /*  ctor / fields                                                        */
    /* --------------------------------------------------------------------- */

    private Client       $client;
    private FileCacheStorage $store;
    private SplQueue     $events;

    /** @var array<string,ByteWriteStream> */
    private array $writers = [];

    /** @var array<string,bool> HEAD requests we issued for stale entries */
    private array $pendingHead = [];

    private int $chunkSize;

    /*  current event snapshot -------------------------------------------- */
    private $currentEvent   = null;
    private ?Request  $currentRequest = null;
    private ?Response $currentResp    = null;
    private ?string   $currentChunk   = null;

    public function __construct( Client $client, string $cacheDir, int $chunkSize = self::DEFAULT_CHUNK_SIZE ) {
        $this->client    = $client;
        $this->store     = new FileCacheStorage( rtrim( $cacheDir, DIRECTORY_SEPARATOR ) );
        $this->events    = new SplQueue();
        $this->chunkSize = $chunkSize;
    }

    /* --------------------------------------------------------------------- */
    /*  PUBLIC API                                                           */
    /* --------------------------------------------------------------------- */

    /**
     * Queue one or many Request objects or URL strings for execution with
     * caching semantics applied upfront.
     */
    public function enqueue( Request|array|string $requests ): void {
        $list      = is_array( $requests ) ? $requests : [ $requests ];
        $toForward = [];

        foreach ( $list as $req ) {
            // normalise ---------------------------------------------------------
            if ( is_string( $req ) ) {
                $req = new Request( $req ); // GET by default
            }
            if ( ! $req instanceof Request ) {
                continue; // ignore garbage – inner client would, too
            }

            // we only care about GETs for caching --------------------------------
            if ( strtoupper( $req->method ) !== 'GET' ) {
                $toForward[] = $req;
                continue;
            }

            $hit = $this->store->lookup( $req->url );

            /* fresh hit → replay from cache ------------------------------------ */
            if ( $hit && CachePolicy::is_fresh( $hit ) && $hit->status === 200 ) {
                $resp              = new Response( $req );
                $resp->status_code = $hit->status;
                $resp->headers     = $hit->headers;

                $this->queueCachedStream( $req, $resp, $this->store->body_path( $req->url ) );
                continue;
            }

            /* stale hit → issue conditional HEAD ------------------------------- */
            if ( $hit ) {
                $head            = new Request( $req->url ); // default GET, mutate
                $head->method    = 'HEAD';
                if ( $hit->etag )         { $head->headers['If-None-Match']     = $hit->etag; }
                if ( $hit->last_modified ){ $head->headers['If-Modified-Since'] = $hit->last_modified; }

                $this->pendingHead[ spl_object_hash( $head ) ] = true;
                $toForward[] = $head;
                continue;
            }

            /* miss → forward as‑is --------------------------------------------- */
            $toForward[] = $req;
        }

        if ( $toForward ) {
            $this->client->enqueue( $toForward );
        }
    }

    /**
     * Advance by exactly one event (synthetic or real) and expose it through
     * the getter trio.
     */
    public function await_next_event( array $query = [] ): bool {
        // 1. synthetic first ----------------------------------------------------
        if ( ! $this->events->isEmpty() ) {
            $this->popSynthetic();
            return true;
        }

        // 2. drive wrapped client ----------------------------------------------
        if ( ! $this->client->await_next_event( $query ) ) {
            return false;
        }

        $this->currentEvent   = $this->client->get_event();
        $this->currentRequest = $this->client->get_request();

        switch ( $this->currentEvent ) {
            case Client::EVENT_GOT_HEADERS:
                $this->currentResp = $this->client->get_request()->response;
                $this->onHeaders( $this->currentRequest, $this->currentResp );
                break;

            case Client::EVENT_BODY_CHUNK_AVAILABLE:
                $chunk              = $this->client->get_response_body_chunk();
                $this->currentChunk = $chunk;
                $this->onBodyChunk( $this->currentRequest, $chunk );
                break;

            case Client::EVENT_FINISHED:
                $this->currentResp = $this->client->get_request()->response;
                $this->onFinished( $this->currentRequest, $this->currentResp );
                break;
        }

        // mutating verbs always bust the cache ---------------------------------
        if ( in_array( strtoupper( $this->currentRequest->method ), [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ) {
            $this->store->invalidate( $this->currentRequest->url );
        }

        return true;
    }

    /* --------------------------------------------------------------------- */
    /*  getter trio                                                           */
    /* --------------------------------------------------------------------- */

    public function get_event()                   { $e = $this->currentEvent;   $this->currentEvent = null; return $e; }
    public function get_request(): ?Request       { return $this->currentRequest; }
    public function get_response(): ?Response     { return $this->currentResp;    }
    public function get_response_body_chunk():?string { $c = $this->currentChunk; $this->currentChunk = null; return $c; }

    /* --------------------------------------------------------------------- */
    /*  inner‑client interception                                             */
    /* --------------------------------------------------------------------- */

    private function onHeaders( Request $req, Response $res ): void {
        // HEAD responses never get cached bodies ------------------------------
        if ( strtoupper( $req->method ) === 'HEAD' ) {
            return;
        }

        // 304 → swap in cached representation ---------------------------------
        if ( $res->status_code === 304 ) {
            $hit = $this->store->lookup( $req->url );
            if ( $hit ) {
                $cached              = new Response( $req );
                $cached->status_code = $hit->status;
                $cached->headers     = $hit->headers;

                $this->queueCachedStream( $req, $cached, $this->store->body_path( $req->url ) );
            }
            return; // no writer for 304
        }

        // prepare writer if storable -----------------------------------------
        if ( CachePolicy::response_is_cacheable( $res ) ) {
            $this->writers[ spl_object_hash( $req ) ] = $this->store->open_body_write_stream( $req->url );
        }
    }

    private function onBodyChunk( Request $req, string $chunk ): void {
        $hash = spl_object_hash( $req );
        if ( isset( $this->writers[ $hash ] ) ) {
            $this->writers[ $hash ]->append_bytes( $chunk );
        }
    }

    private function onFinished( Request $req, Response $res ): void {
        $hash = spl_object_hash( $req );

        // special handling for our HEAD probes ---------------------------------
        if ( isset( $this->pendingHead[ $hash ] ) ) {
            unset( $this->pendingHead[ $hash ] );
            $this->handleHeadResult( $req, $res );
            return; // HEAD has no body writer etc.
        }

        // regular GET finished -------------------------------------------------
        if ( isset( $this->writers[ $hash ] ) ) {
            $this->writers[ $hash ]->close_writing();
            unset( $this->writers[ $hash ] );
        }

        if ( ! CachePolicy::response_is_cacheable( $res ) ) {
            return;
        }

        $entry              = new CacheEntry();
        $entry->url         = $req->url;
        $entry->status      = $res->status_code;
        $entry->headers     = $res->headers;
        $entry->etag        = $res->get_header( 'etag' );
        $entry->last_modified = $res->get_header( 'last-modified' );

        $this->store->store( $entry );
    }

    /**
     * Decide what to do after a conditional HEAD returns.
     */
    private function handleHeadResult( Request $headReq, Response $headResp ): void {
        $hit = $this->store->lookup( $headReq->url );

        // 304 – still valid ----------------------------------------------------
        if ( $headResp->status_code === 304 ) {
            $cached              = new Response( new Request( $headReq->url ) );
            $cached->status_code = $hit->status;
            $cached->headers     = $hit->headers;
            $this->queueCachedStream( new Request( $headReq->url ), $cached, $this->store->body_path( $headReq->url ) );
            return;
        }

        // 200 – compare validation headers ------------------------------------
        $etagSame = $hit && $hit->etag && $headResp->get_header( 'etag' ) === $hit->etag;
        $lmSame   = $hit && $hit->last_modified && $headResp->get_header( 'last-modified' ) === $hit->last_modified;

        if ( $etagSame || $lmSame ) {
            // unchanged even though server replied 200; serve cache ------------
            $cached              = new Response( new Request( $headReq->url ) );
            $cached->status_code = $hit->status;
            $cached->headers     = $hit->headers;
            $this->queueCachedStream( new Request( $headReq->url ), $cached, $this->store->body_path( $headReq->url ) );
            return;
        }

        // changed – issue unconditional GET -----------------------------------
        $getReq = new Request( $headReq->url ); // GET by default
        $this->client->enqueue( $getReq );
    }

    /* --------------------------------------------------------------------- */
    /*  Synthetic‑event machinery                                            */
    /* --------------------------------------------------------------------- */

    private const SYN_STREAM = 0xdecaf; // internal marker only

    private function queueCachedStream( Request $req, Response $resp, string $file ): void {
        $stream = new SplFileObject( $file, 'rb' );
        $this->events->enqueue( [ Client::EVENT_GOT_HEADERS, $req, $resp ] );
        $this->events->enqueue( [ self::SYN_STREAM, $req, $stream, $resp ] );
    }

    private function popSynthetic(): void {
        $item = $this->events->dequeue();
        [ $type, $req ] = $item;

        $this->currentRequest = $req;

        if ( $type === self::SYN_STREAM ) {
            $stream = $item[2];
            $resp   = $item[3];

            $chunk = $stream->fread( $this->chunkSize );
            if ( $chunk === '' ) {
                $this->currentEvent = Client::EVENT_FINISHED;
                $this->currentResp  = $resp;
                $this->currentChunk = null;
            } else {
                $this->currentEvent = Client::EVENT_BODY_CHUNK_AVAILABLE;
                $this->currentResp  = null;
                $this->currentChunk = $chunk;
                $this->events->unshift( [ self::SYN_STREAM, $req, $stream, $resp ] );
            }
            return;
        }

        // regular queued synthetic -------------------------------------------
        $this->currentEvent = $type;
        if ( $type === Client::EVENT_BODY_CHUNK_AVAILABLE ) {
            $this->currentChunk = $item[2];
            $this->currentResp  = null;
        } else {
            $this->currentResp  = $item[2];
            $this->currentChunk = null;
        }
    }
}

/* ===================================================================== */
/*  Minimal on‑disk CacheStorage                                         */
/* ===================================================================== */
class FileCacheStorage {

    private string $dir;
    private array  $tmp = [];

    public function __construct( string $dir ) {
        if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) ) {
            throw new \RuntimeException( "Unable to create cache dir {$dir}" );
        }
        $this->dir = $dir;
    }

    private function key( string $url ): string { return sha1( $url ); }

    public function body_path( string $url ): string {
        return "{$this->dir}/{$this->key( $url )}.body";
    }

    public function lookup( string $url ): ?CacheEntry {
        $meta = "{$this->dir}/{$this->key( $url )}.json";
        if ( ! is_file( $meta ) ) {
            return null;
        }
        $data  = json_decode( file_get_contents( $meta ), true );
        $e     = new CacheEntry();
        foreach ( $data as $k => $v ) { $e->$k = $v; }
        return $e;
    }

    public function open_body_write_stream( string $url ): ByteWriteStream {
        $tmp              = "{$this->dir}/{$this->key( $url )}.tmp";
        $this->tmp[ $url ] = $tmp;
        return FileWriteStream::from_path( $tmp );
    }

    public function store( CacheEntry $e ): void {
        $hash = $this->key( $e->url );
        $tmp  = $this->tmp[ $e->url ] ?? null;
        $body = "{$this->dir}/{$hash}.body";

        if ( $tmp && is_file( $tmp ) ) {
            rename( $tmp, $body );
        }

        file_put_contents( "{$this->dir}/{$hash}.json", json_encode( $e, JSON_UNESCAPED_SLASHES ) );
    }

    public function invalidate( string $url ): void {
        $hash = $this->key( $url );
        @unlink( "{$this->dir}/{$hash}.json" );
        @unlink( "{$this->dir}/{$hash}.body" );
    }
}
