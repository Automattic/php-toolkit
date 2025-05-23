<?php

namespace WordPress\HttpClient;

use RuntimeException;
use Exception;

/**
 * Simple, process-safe cache that avoids the Filesystem abstraction and
 * relies on atomic renames. All writes go to <name>.partial first, then
 * get renamed into place. Every critical section is wrapped in flock().
 */
final class FilesystemCache
{
    /** @var string */
    private string $dir;

    /** @var array<string,string> */
    private array $partials = [];

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir) && !mkdir($this->dir, 0755, true)) {
            throw new RuntimeException("Cannot create cache dir {$this->dir}");
        }
    }

    private function key(string $url): string
    {
        return hash('sha256', $url);
    }

    public function get_body_path(string $url): string
    {
        return "{$this->dir}/{$this->key($url)}.bin";
    }

    private function get_meta_path(string $url): string
    {
        return "{$this->dir}/{$this->key($url)}.json";
    }

    /** @return resource */
    public function open_body_write_stream(string $url)
    {
        $partial = $this->get_body_path($url) . '.partial';
        $h = fopen($partial, 'wb');
        if (!$h) {
            throw new RuntimeException("Cannot open {$partial} for writing");
        }
        if (!flock($h, LOCK_EX)) {
            fclose($h);
            throw new RuntimeException("Cannot get exclusive lock on {$partial}");
        }
        $this->partials[$url] = $partial;
        return $h;              // caller must fclose(); promotion happens in store()
    }

	public function commit(Response $response): void
	{
		$e = CacheEntry::from_response( $response );
		
        /* promote body if it was streamed */
        if (isset($this->partials[$e->url])) {
            $partial = $this->partials[$e->url];
            $final   = $this->get_body_path($e->url);
            fclose(fopen($partial, 'rb+'));   // flush & unlock
            rename($partial, $final);         // atomic within fs
            chmod($final, 0644);
            unset($this->partials[$e->url]);
        }

        $json = json_encode($e, JSON_PRETTY_PRINT);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(json_last_error_msg());
        }

        $meta = $this->get_meta_path($e->url);
        $tmp  = $meta . '.partial';
        $h    = fopen($tmp, 'wb');
        if (!$h) {
            throw new RuntimeException("Cannot write {$tmp}");
        }
        flock($h, LOCK_EX);
        fwrite($h, $json);
        fflush($h);
        if (function_exists('fsync')) {
            fsync($h);
        }
        flock($h, LOCK_UN);
        fclose($h);
        rename($tmp, $meta);
        chmod($meta, 0644);
    }

    public function lookup(string $url): ?CacheEntry
    {
        $meta = $this->get_meta_path($url);
        $body = $this->get_body_path($url);

        if (!is_file($meta) || !is_file($body)) {
            $this->invalidate($url);
            return null;
        }

        $h = fopen($meta, 'rb');
        if (!$h) {
            $this->invalidate($url);
            return null;
        }
        flock($h, LOCK_SH);
        $json = stream_get_contents($h);
        flock($h, LOCK_UN);
        fclose($h);

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->invalidate($url);
            return null;
        }

        $entry = new CacheEntry();
        foreach ($data as $k => $v) {
            if ($k === 'body_path') {   // legacy field
                continue;
            }
            $entry->$k = $v;
        }

        if ($entry->url !== $url) {     // hash collision guard
            $this->invalidate($url);
            return null;
        }

        return $entry;
    }

    public function get_body(CacheEntry $e): string
    {
        $body = $this->get_body_path($e->url);
        if (!is_file($body)) {
            $this->invalidate($e->url);
            throw new RuntimeException("Cache body missing for {$e->url}");
        }

        $h = fopen($body, 'rb');
        if (!$h) {
            throw new RuntimeException("Cannot open body for {$e->url}");
        }
        flock($h, LOCK_SH);
        $data = stream_get_contents($h);
        flock($h, LOCK_UN);
        fclose($h);

        return $data;
    }

    public function invalidate(string $url): void
    {
        @unlink($this->get_meta_path($url));
        @unlink($this->get_body_path($url));
        @unlink($this->get_body_path($url) . '.partial');
        unset($this->partials[$url]);
    }
}
