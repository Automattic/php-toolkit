<?php
namespace WordPress\ByteStream;

use ArrayAccess;
use WordPress\ByteStream\Filter\ByteFilter;
use WordPress\ByteStream\Reader\ByteReader;

class ReadStream implements ByteReader, ArrayAccess {

    /**
     * @var ByteReader
     */
    private $reader;

    /**
     * @var ByteFilter[]
     */
    private $filters = [];

    /**
     * @var MemoryPipe
     */
    private $output;

    private $flushed = false;

    public function __construct(ByteReader $reader, array $filters=[]) {
        $this->reader = $reader;
        $this->filters = $filters;
        $this->output = new MemoryPipe();
    }

    public function get_upstream_reader(): ByteReader {
        return $this->reader;
    }

    public function next_bytes($max_bytes = 8096): bool {
        if(!$this->reader->next_bytes($max_bytes)) {
            if($this->reader->reached_end_of_data() && !$this->flushed) {
                $this->output->append_bytes($this->flush_filters());
                $this->output->next_bytes();
                $this->flushed = true;
                return true;
            }
            return false;
        }

        $chunk = $this->reader->get_bytes();
        foreach($this->filters as $filter) {
            $chunk = $filter->filter_bytes($chunk);
            if($chunk === false) {
                return false;
            }
        }

        $this->output->append_bytes($chunk);
        return $this->output->next_bytes($max_bytes);
    }

    public function get_bytes(): string {
        return $this->output->get_bytes();
    }

    private function flush_filters(): string {
        $flush = '';
        foreach($this->filters as $filter) {
            $flush = $filter->filter_bytes($flush);
            $flush .= $filter->flush();
        }
        return $flush;
    }

    /** @disregard P1038 */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset) {
        if(!isset($this->filters[$offset])) {
            throw new ByteStreamException(sprintf('Filter %s not found', $offset));
        }
        return $this->filters[$offset];
    }

    /** @disregard P1038 */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset) {
        return isset($this->filters[$offset]);
    }

    /** @disregard P1038 */
    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value) {
        throw new ByteStreamException('Filters are immutable');
    }

    /** @disregard P1038 */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset) {
        throw new ByteStreamException('Filters are immutable');
    }

    public function length(): int {
        throw new ByteStreamException('The length of the ReadStream is not known upfront');
    }

    public function tell(): int {
        throw new ByteStreamException('Tell is not supported on ReadStream');
    }

    public function seek($offset): bool {
        throw new ByteStreamException('Seek is not supported on ReadStream');
    }

    public function reached_end_of_data(): bool {
        return $this->reader->reached_end_of_data();
    }

    public function close(): void {}

}
