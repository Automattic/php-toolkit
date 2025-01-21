<?php
namespace WordPress\ByteStream;

use ArrayAccess;
use WordPress\ByteStream\Filter\ByteFilter;
use WordPress\ByteStream\Writer\ByteWriter;

class WriteStream implements ByteWriter, ArrayAccess {

    /**
     * @var ByteWriter
     */
    private $writer;

    /**
     * @var ByteFilter[]
     */
    private $filters = [];

    public function __construct(ByteWriter $writer, array $filters = []) {
        $this->writer = $writer;
        $this->filters = $filters;
    }

    public function append_bytes(string $chunk): void {
        foreach($this->filters as $filter) {
            $chunk = $filter->filter_bytes($chunk);
            if($chunk === false) {
                return;
            }
        }

        $this->writer->append_bytes($chunk);
    }

    public function get_downstream_writer(): ByteWriter {
        return $this->writer;
    }

    public function close(): void {
        foreach($this->filters as $filter) {
            $this->writer->append_bytes(
                $filter->flush()
            );
        }
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
        $this->filters[$offset] = $value;
    }

    /** @disregard P1038 */
    #[\ReturnTypeWillChange]
    public function offsetUnset($offset) {
        unset($this->filters[$offset]);
    }

}
