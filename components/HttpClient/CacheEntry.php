<?php

namespace WordPress\HttpClient;

final class CacheEntry {
	public string  $url;
	public int     $status;
	public array   $headers;
	public int     $stored_at;
	public ?int    $max_age;
	public ?string $etag;
	public ?string $last_modified;
}
